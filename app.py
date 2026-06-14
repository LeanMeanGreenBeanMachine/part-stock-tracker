# app.py — Vulcan Stock Tracker
# Flask application for managing inventory across Rozet and Recluse offices.

import os
import smtplib
import requests as http
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from datetime import datetime, timezone
from math import floor
from functools import wraps

from flask import (
    Flask, render_template, request, redirect,
    url_for, session, flash, jsonify, abort
)
from flask_sqlalchemy import SQLAlchemy
from dotenv import load_dotenv

load_dotenv()

# ── App Setup ─────────────────────────────────────────────────────────────────

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'dev-secret-CHANGE-IN-PRODUCTION')

_db_url = os.environ.get('DATABASE_URL', 'sqlite:///stock.db')
# Render's Postgres URLs start with postgres://, but SQLAlchemy needs postgresql://
if _db_url.startswith('postgres://'):
    _db_url = _db_url.replace('postgres://', 'postgresql://', 1)

app.config['SQLALCHEMY_DATABASE_URI'] = _db_url
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False

db = SQLAlchemy(app)

# ── Auth ──────────────────────────────────────────────────────────────────────

_APP_USER = 'homesteader'
_APP_PASS = 'm4nksrwvav7m'

# ── Product BOMs (hardcoded) ──────────────────────────────────────────────────
# "used_parts" → deducted from inventory when an order is logged
# "contains"   → display only, no inventory impact

PRODUCTS = {
    "2 Foot Cable": {
        "used_parts": {
            "Terminals": 3,
            "Wire Seals": 3,
            "Connectors": 1,
            "Full Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "2_foot_cable.png",
    },
    "4 Foot Cable": {
        "used_parts": {
            "Terminals": 3,
            "Wire Seals": 3,
            "Connectors": 1,
            "Full Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "4_foot_cable.png",
    },
    "Short Cable": {
        "used_parts": {
            "Terminals": 3,
            "Wire Seals": 3,
            "Connectors": 1,
            "Short Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "short_cable.png",
    },
    "Rear Box": {
        "used_parts": {
            "Terminals": 3,
            "Connectors": 1,
            "Aux Ports": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 0.5,
            "Box Lids": 1,
            "Rear Boxes": 1,
        },
        "contains": ["Black Wire", "Red Wire", "UV Resin", "Thread Locker", "Solder"],
        "image": "rear_box.png",
    },
    "Front Box": {
        "used_parts": {
            "Terminals": 3,
            "Connectors": 1,
            "Aux Ports": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 0.5,
            "Box Lids": 1,
            "Front Boxes": 1,
        },
        "contains": ["Black Wire", "Red Wire", "UV Resin", "Thread Locker", "Solder"],
        "image": "front_box.png",
    },
}

# Part name → static image filename
PART_IMAGES = {
    "Terminals":        "terminals.png",
    "Wire Seals":       "wire_seals.png",
    "Aux Ports":        "aux_ports.png",
    "Connectors":       "connectors.png",
    "Box Lids":         "box_lids.png",
    "Rear Boxes":       "rear_boxes.png",
    "Front Boxes":      "front_boxes.png",
    "Small Shrink Tube":"small_shrink_tube.png",
    "Large Shrink Tube":"large_shrink_tube.png",
    "Envelopes":        "envelopes.png",
    "Full Cables":      "full_cables.png",
    "Short Cables":     "short_cables.png",
    "Aux Port Nuts":    "aux_port_nuts.png",
}

SEED_PARTS = list(PART_IMAGES.keys())

# Colors for Chart.js lines (one per part, 13 total)
_CHART_COLORS = [
    '#39ff14', '#00cfff', '#ff4444', '#ffaa00', '#cc00ff',
    '#00ff88', '#ff6eb4', '#ffe100', '#4fc3f7', '#a5d6a7',
    '#ff8f00', '#b0bec5', '#f48fb1',
]

# ── Models ────────────────────────────────────────────────────────────────────

class Office(db.Model):
    __tablename__ = 'offices'
    id   = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False, unique=True)

    inventories     = db.relationship('Inventory',           backref='office', cascade='all, delete-orphan')
    product_logs    = db.relationship('ProductLog',          backref='office', cascade='all, delete-orphan')
    inventory_logs  = db.relationship('InventoryLog',        backref='office', cascade='all, delete-orphan')
    settings        = db.relationship('OfficeSetting',       backref='office', uselist=False, cascade='all, delete-orphan')
    alert_state     = db.relationship('OfficeAlertState',    backref='office', uselist=False, cascade='all, delete-orphan')
    contact_settings= db.relationship('OfficeContactSetting',backref='office', cascade='all, delete-orphan')


class Part(db.Model):
    __tablename__ = 'parts'
    id   = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False, unique=True)
    unit = db.Column(db.String(50), default='units')

    inventories = db.relationship('Inventory', backref='part', cascade='all, delete-orphan')


class Inventory(db.Model):
    __tablename__ = 'inventory'
    id        = db.Column(db.Integer, primary_key=True)
    office_id = db.Column(db.Integer, db.ForeignKey('offices.id', ondelete='CASCADE'), nullable=False)
    part_id   = db.Column(db.Integer, db.ForeignKey('parts.id',   ondelete='CASCADE'), nullable=False)
    quantity  = db.Column(db.Float, default=0.0, nullable=False)
    __table_args__ = (db.UniqueConstraint('office_id', 'part_id'),)


class ProductLog(db.Model):
    __tablename__ = 'product_logs'
    id           = db.Column(db.Integer, primary_key=True)
    office_id    = db.Column(db.Integer, db.ForeignKey('offices.id', ondelete='CASCADE'), nullable=False)
    product_name = db.Column(db.String(100), nullable=False)
    timestamp    = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False)
    quantity     = db.Column(db.Integer, default=1, nullable=False)
    struck       = db.Column(db.Boolean, default=False)  # True when reversed via "Strike From Log"


class InventoryLog(db.Model):
    __tablename__ = 'inventory_logs'
    id                 = db.Column(db.Integer, primary_key=True)
    office_id          = db.Column(db.Integer, db.ForeignKey('offices.id', ondelete='CASCADE'), nullable=False)
    part_name          = db.Column(db.String(100), nullable=False)
    # change_type values: "add", "subtract", "transfer", "order_log", "order_strike"
    change_type        = db.Column(db.String(50), nullable=False)
    amount             = db.Column(db.Float, nullable=False)
    resulting_quantity = db.Column(db.Float)  # stock level AFTER this change (used for chart)
    timestamp          = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False)
    note               = db.Column(db.String(255))


class Contact(db.Model):
    __tablename__ = 'contacts'
    id                  = db.Column(db.Integer, primary_key=True)
    label               = db.Column(db.String(100))                 # friendly display name
    method              = db.Column(db.String(20), nullable=False)  # "Email", "Telegram", "Both"
    email               = db.Column(db.String(255))
    telegram_bot_token  = db.Column(db.String(255))
    telegram_chat_id    = db.Column(db.String(100))

    office_settings = db.relationship('OfficeContactSetting', backref='contact', cascade='all, delete-orphan')


class OfficeContactSetting(db.Model):
    __tablename__ = 'office_contact_settings'
    id                    = db.Column(db.Integer, primary_key=True)
    office_id             = db.Column(db.Integer, db.ForeignKey('offices.id',   ondelete='CASCADE'), nullable=False)
    contact_id            = db.Column(db.Integer, db.ForeignKey('contacts.id',  ondelete='CASCADE'), nullable=False)
    notifications_enabled = db.Column(db.Boolean, default=False)
    __table_args__ = (db.UniqueConstraint('office_id', 'contact_id'),)


class OfficeSetting(db.Model):
    __tablename__ = 'office_settings'
    id                  = db.Column(db.Integer, primary_key=True)
    office_id           = db.Column(db.Integer, db.ForeignKey('offices.id', ondelete='CASCADE'), nullable=False, unique=True)
    low_stock_threshold = db.Column(db.Integer, default=3, nullable=False)


class OfficeAlertState(db.Model):
    __tablename__ = 'office_alert_states'
    id               = db.Column(db.Integer, primary_key=True)
    office_id        = db.Column(db.Integer, db.ForeignKey('offices.id', ondelete='CASCADE'), nullable=False, unique=True)
    is_currently_low = db.Column(db.Boolean, default=False)
    last_notified_at = db.Column(db.DateTime)


# ── Helpers ───────────────────────────────────────────────────────────────────

def login_required(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if not session.get('logged_in'):
            return redirect(url_for('login'))
        return f(*args, **kwargs)
    return decorated


def get_inventory_map(office_id):
    """Return {part_name: quantity} dict for the given office."""
    rows = (
        db.session.query(Part.name, Inventory.quantity)
        .join(Inventory, Part.id == Inventory.part_id)
        .filter(Inventory.office_id == office_id)
        .all()
    )
    return {name: qty for name, qty in rows}


def calculate_buildable(inventory_map, product_name):
    """
    Return (max_buildable_units, bottleneck_part_name) for a product given
    the current inventory. Uses floor division because partial units don't count.
    """
    bom = PRODUCTS[product_name]['used_parts']
    min_buildable = float('inf')
    bottleneck = None

    for part, needed in bom.items():
        if needed <= 0:
            continue
        can_build = floor(inventory_map.get(part, 0) / needed)
        if can_build < min_buildable:
            min_buildable = can_build
            bottleneck = part

    return (0, None) if min_buildable == float('inf') else (min_buildable, bottleneck)


def calculate_lowest_buildable(office_id):
    """
    Return (lowest_buildable, bottleneck_part, worst_product_name) across
    all 5 products for the given office. Used for threshold checks.
    """
    inv = get_inventory_map(office_id)
    results = [
        (calculate_buildable(inv, name)[0], calculate_buildable(inv, name)[1], name)
        for name in PRODUCTS
    ]
    results.sort(key=lambda x: x[0])
    return results[0] if results else (0, None, None)


def get_other_office(office_id):
    return Office.query.filter(Office.id != office_id).first()


def _record_inventory_change(office_id, part_name, change_type, amount, resulting_qty, note=None):
    """Append a row to inventory_logs. Always call AFTER updating inv.quantity."""
    db.session.add(InventoryLog(
        office_id=office_id,
        part_name=part_name,
        change_type=change_type,
        amount=amount,
        resulting_quantity=resulting_qty,
        note=note,
    ))


def _get_or_create_inv(office_id, part_id):
    inv = Inventory.query.filter_by(office_id=office_id, part_id=part_id).first()
    if not inv:
        inv = Inventory(office_id=office_id, part_id=part_id, quantity=0.0)
        db.session.add(inv)
        db.session.flush()
    return inv


# ── Notifications ─────────────────────────────────────────────────────────────

def _send_email(contact, subject, body):
    host   = os.environ.get('SMTP_HOST', 'smtp.gmail.com')
    port   = int(os.environ.get('SMTP_PORT', 587))
    user   = os.environ.get('SMTP_USER', '')
    passwd = os.environ.get('SMTP_PASS', '')
    sender = os.environ.get('SMTP_FROM', user)

    msg = MIMEMultipart()
    msg['Subject'] = subject
    msg['From']    = sender
    msg['To']      = contact.email
    msg.attach(MIMEText(body, 'plain'))

    try:
        with smtplib.SMTP(host, port) as srv:
            srv.starttls()
            srv.login(user, passwd)
            srv.sendmail(sender, contact.email, msg.as_string())
        return True
    except Exception as exc:
        app.logger.error('Email failed: %s', exc)
        return False


def _send_telegram(contact, message):
    url = f"https://api.telegram.org/bot{contact.telegram_bot_token}/sendMessage"
    try:
        r = http.post(url, json={'chat_id': contact.telegram_chat_id, 'text': message}, timeout=10)
        return r.ok
    except Exception as exc:
        app.logger.error('Telegram failed: %s', exc)
        return False


def _dispatch(contact, office_name, lowest_buildable, bottleneck, product_name):
    subject = f"[Vulcan] Low Stock — {office_name}"
    body = (
        f"⚠️  Low stock alert for {office_name}.\n\n"
        f"You can build only {lowest_buildable} unit(s) of '{product_name}' "
        f"(and possibly other products).\n"
        f"Current bottleneck: {bottleneck}\n\n"
        f"Please reorder soon."
    )
    if contact.method in ('Email', 'Both') and contact.email:
        _send_email(contact, subject, body)
    if contact.method in ('Telegram', 'Both') and contact.telegram_bot_token:
        _send_telegram(contact, body)


# ── Routes — Auth ─────────────────────────────────────────────────────────────

@app.route('/')
def index():
    if not session.get('logged_in'):
        return redirect(url_for('login'))
    return redirect(url_for('dashboard', office_id=1, section='main_menu'))


@app.route('/login', methods=['GET', 'POST'])
def login():
    if session.get('logged_in'):
        return redirect(url_for('index'))
    error = None
    if request.method == 'POST':
        u = request.form.get('username', '').strip().lower()
        p = request.form.get('password', '').strip().lower()
        if u == _APP_USER and p == _APP_PASS:
            session['logged_in'] = True
            session.permanent = True
            return redirect(url_for('index'))
        error = 'Invalid username or password.'
    return render_template('login.html', error=error)


@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))


# ── Routes — Dashboard Shell ───────────────────────────────────────────────────

@app.route('/dashboard')
@login_required
def dashboard():
    office_id = request.args.get('office_id', 1, type=int)
    section   = request.args.get('section', 'main_menu')

    valid_sections = {'main_menu', 'update_inventory', 'product_history', 'inventory_history', 'settings'}
    if section not in valid_sections:
        section = 'main_menu'

    offices = Office.query.order_by(Office.id).all()
    office  = Office.query.get_or_404(office_id)

    return render_template(
        'dashboard.html',
        offices=offices,
        office=office,
        section=section,
        products=PRODUCTS,
        part_images=PART_IMAGES,
    )


# ── Routes — Partials (loaded by JS fetch / HTMX) ─────────────────────────────

@app.route('/partials/main_menu')
@login_required
def partial_main_menu():
    office_id     = request.args.get('office_id', 1, type=int)
    office        = Office.query.get_or_404(office_id)
    inventory_map = get_inventory_map(office_id)
    parts         = Part.query.order_by(Part.name).all()

    product_data = {}
    for name, info in PRODUCTS.items():
        buildable, bottleneck = calculate_buildable(inventory_map, name)
        product_data[name] = {**info, 'buildable': buildable, 'bottleneck': bottleneck}

    return render_template(
        'partials/main_menu.html',
        office=office,
        product_data=product_data,
        inventory_map=inventory_map,
        parts=parts,
        part_images=PART_IMAGES,
    )


@app.route('/partials/update_inventory')
@login_required
def partial_update_inventory():
    office_id     = request.args.get('office_id', 1, type=int)
    office        = Office.query.get_or_404(office_id)
    other_office  = get_other_office(office_id)
    parts         = Part.query.order_by(Part.name).all()
    inventory_map = get_inventory_map(office_id)

    return render_template(
        'partials/update_inventory.html',
        office=office,
        other_office=other_office,
        parts=parts,
        inventory_map=inventory_map,
        part_images=PART_IMAGES,
    )


@app.route('/partials/product_history')
@login_required
def partial_product_history():
    office_id     = request.args.get('office_id', 1, type=int)
    office        = Office.query.get_or_404(office_id)
    logs = (
        ProductLog.query
        .filter_by(office_id=office_id, struck=False)
        .order_by(ProductLog.timestamp.desc())
        .limit(50)
        .all()
    )
    return render_template(
        'partials/product_history.html',
        office=office,
        logs=logs,
        product_names=list(PRODUCTS.keys()),
    )


@app.route('/partials/inventory_history')
@login_required
def partial_inventory_history():
    office_id   = request.args.get('office_id', 1, type=int)
    office      = Office.query.get_or_404(office_id)
    recent_logs = (
        InventoryLog.query
        .filter_by(office_id=office_id)
        .order_by(InventoryLog.timestamp.desc())
        .limit(50)
        .all()
    )
    parts            = Part.query.order_by(Part.name).all()
    chart_datasets   = _build_chart_datasets(office_id, parts)

    return render_template(
        'partials/inventory_history.html',
        office=office,
        recent_logs=recent_logs,
        chart_datasets=chart_datasets,
    )


@app.route('/partials/settings')
@login_required
def partial_settings():
    office_id  = request.args.get('office_id', 1, type=int)
    office     = Office.query.get_or_404(office_id)
    settings   = OfficeSetting.query.filter_by(office_id=office_id).first()
    contacts   = Contact.query.all()

    contact_state = {}
    for c in contacts:
        ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=c.id).first()
        contact_state[c.id] = ocs.notifications_enabled if ocs else False

    lowest_buildable, bottleneck, _ = calculate_lowest_buildable(office_id)

    return render_template(
        'partials/settings.html',
        office=office,
        settings=settings,
        contacts=contacts,
        contact_state=contact_state,
        lowest_buildable=lowest_buildable,
        bottleneck=bottleneck,
    )


def _build_chart_datasets(office_id, parts):
    """
    Build Chart.js time-series datasets from stored InventoryLog rows.
    Each part gets one dataset using the resulting_quantity column (set at write time).
    """
    all_logs = (
        InventoryLog.query
        .filter(InventoryLog.office_id == office_id,
                InventoryLog.resulting_quantity.isnot(None))
        .order_by(InventoryLog.timestamp.asc())
        .all()
    )

    datasets = []
    inv_map  = get_inventory_map(office_id)

    for i, part in enumerate(parts):
        part_logs = [l for l in all_logs if l.part_name == part.name]
        points = [
            {'x': l.timestamp.strftime('%Y-%m-%dT%H:%M:%S'), 'y': l.resulting_quantity}
            for l in part_logs
        ]
        # Append current value so the line reaches "now"
        current_qty = inv_map.get(part.name, 0)
        points.append({'x': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S'), 'y': current_qty})

        color = _CHART_COLORS[i % len(_CHART_COLORS)]
        datasets.append({
            'label':           part.name,
            'data':            points,
            'borderColor':     color,
            'backgroundColor': color + '33',
            'tension':         0.3,
            'fill':            False,
            'pointRadius':     2,
        })

    return datasets


# ── Routes — Actions ──────────────────────────────────────────────────────────

@app.route('/api/log_order', methods=['POST'])
@login_required
def log_order():
    """Atomically deduct BOM parts from inventory and record a ProductLog entry."""
    office_id    = request.form.get('office_id', type=int)
    product_name = request.form.get('product_name', '').strip()

    if product_name not in PRODUCTS:
        flash('Unknown product.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='main_menu'))

    bom           = PRODUCTS[product_name]['used_parts']
    inventory_map = get_inventory_map(office_id)
    buildable, bottleneck = calculate_buildable(inventory_map, product_name)

    if buildable < 1:
        flash(f'Not enough stock to build "{product_name}". Bottleneck: {bottleneck}.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='main_menu'))

    try:
        for part_name, amount in bom.items():
            part = Part.query.filter_by(name=part_name).first()
            if not part:
                continue
            inv = _get_or_create_inv(office_id, part.id)
            inv.quantity = max(0.0, inv.quantity - amount)
            _record_inventory_change(office_id, part_name, 'order_log', amount,
                                     inv.quantity, note=f'Used for: {product_name}')

        db.session.add(ProductLog(office_id=office_id, product_name=product_name))
        db.session.commit()
        flash(f'Order logged: {product_name}', 'success')
    except Exception as exc:
        db.session.rollback()
        app.logger.error('log_order failed: %s', exc)
        flash('Error logging order. Please try again.', 'danger')

    return redirect(url_for('dashboard', office_id=office_id, section='main_menu'))


@app.route('/api/update_inventory', methods=['POST'])
@login_required
def update_inventory():
    """Add or subtract stock for a single part."""
    office_id = request.form.get('office_id', type=int)
    part_name = request.form.get('part_name', '').strip()
    action    = request.form.get('action', '').strip()       # 'add' or 'subtract'
    amount    = request.form.get('amount', type=float)
    note      = request.form.get('note', '').strip() or None

    if not (office_id and part_name and action and amount is not None and amount > 0):
        flash('Please fill in all fields with a positive amount.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id or 1, section='update_inventory'))

    part = Part.query.filter_by(name=part_name).first()
    if not part:
        flash('Unknown part.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='update_inventory'))

    inv = _get_or_create_inv(office_id, part.id)

    try:
        if action == 'add':
            inv.quantity += amount
            _record_inventory_change(office_id, part_name, 'add', amount,
                                     inv.quantity, note=note or 'Stock added')
            flash(f'Added {_fmt(amount)} {part_name}.', 'success')

        elif action == 'subtract':
            if inv.quantity < amount:
                flash(f'Cannot subtract {_fmt(amount)} — only {_fmt(inv.quantity)} in stock.', 'danger')
                return redirect(url_for('dashboard', office_id=office_id, section='update_inventory'))
            inv.quantity = max(0.0, inv.quantity - amount)
            _record_inventory_change(office_id, part_name, 'subtract', amount,
                                     inv.quantity, note=note or 'Stock removed')
            flash(f'Subtracted {_fmt(amount)} {part_name}.', 'success')

        else:
            flash('Invalid action.', 'danger')
            return redirect(url_for('dashboard', office_id=office_id, section='update_inventory'))

        db.session.commit()
    except Exception as exc:
        db.session.rollback()
        app.logger.error('update_inventory failed: %s', exc)
        flash('Error updating inventory.', 'danger')

    return redirect(url_for('dashboard', office_id=office_id, section='update_inventory'))


@app.route('/api/transfer_inventory', methods=['POST'])
@login_required
def transfer_inventory():
    """Transfer stock from the selected office to the other office."""
    from_office_id = request.form.get('office_id', type=int)
    part_name      = request.form.get('part_name', '').strip()
    amount         = request.form.get('amount', type=float)

    if not (from_office_id and part_name and amount and amount > 0):
        flash('Please fill in all fields with a positive amount.', 'danger')
        return redirect(url_for('dashboard', office_id=from_office_id or 1, section='update_inventory'))

    to_office = get_other_office(from_office_id)
    if not to_office:
        flash('No destination office found.', 'danger')
        return redirect(url_for('dashboard', office_id=from_office_id, section='update_inventory'))

    part = Part.query.filter_by(name=part_name).first()
    if not part:
        flash('Unknown part.', 'danger')
        return redirect(url_for('dashboard', office_id=from_office_id, section='update_inventory'))

    from_inv = _get_or_create_inv(from_office_id, part.id)
    if from_inv.quantity < amount:
        flash(f'Insufficient stock: {_fmt(from_inv.quantity)} available, {_fmt(amount)} requested.', 'danger')
        return redirect(url_for('dashboard', office_id=from_office_id, section='update_inventory'))

    to_inv   = _get_or_create_inv(to_office.id, part.id)
    from_office = Office.query.get(from_office_id)

    try:
        from_inv.quantity -= amount
        to_inv.quantity   += amount

        _record_inventory_change(from_office_id, part_name, 'transfer', amount,
                                 from_inv.quantity, note=f'Transfer to {to_office.name}')
        _record_inventory_change(to_office.id, part_name, 'transfer', amount,
                                 to_inv.quantity,   note=f'Transfer from {from_office.name}')

        db.session.commit()
        flash(f'Transferred {_fmt(amount)} {part_name} to {to_office.name}.', 'success')
    except Exception as exc:
        db.session.rollback()
        app.logger.error('transfer_inventory failed: %s', exc)
        flash('Transfer failed. Please try again.', 'danger')

    return redirect(url_for('dashboard', office_id=from_office_id, section='update_inventory'))


@app.route('/api/strike_log/<int:log_id>', methods=['POST'])
@login_required
def strike_log(log_id):
    """Reverse a ProductLog: restore consumed inventory and mark the log struck."""
    log = ProductLog.query.get_or_404(log_id)
    office_id    = log.office_id
    product_name = log.product_name

    if log.struck:
        flash('This log entry has already been struck.', 'warning')
        return redirect(url_for('dashboard', office_id=office_id, section='product_history'))

    bom = PRODUCTS.get(product_name, {}).get('used_parts', {})

    try:
        for part_name, amount in bom.items():
            part = Part.query.filter_by(name=part_name).first()
            if not part:
                continue
            inv = _get_or_create_inv(office_id, part.id)
            inv.quantity += amount
            _record_inventory_change(office_id, part_name, 'order_strike', amount,
                                     inv.quantity, note=f'Strike: {product_name} log #{log_id}')

        log.struck = True
        db.session.commit()
        flash(f'Log #{log_id} struck — inventory restored for "{product_name}".', 'success')
    except Exception as exc:
        db.session.rollback()
        app.logger.error('strike_log failed: %s', exc)
        flash('Strike failed. Please try again.', 'danger')

    return redirect(url_for('dashboard', office_id=office_id, section='product_history'))


@app.route('/api/save_settings', methods=['POST'])
@login_required
def save_settings():
    office_id = request.form.get('office_id', type=int)
    threshold = request.form.get('threshold', type=int)

    if threshold is None or not (3 <= threshold <= 10):
        flash('Threshold must be between 3 and 10.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='settings'))

    s = OfficeSetting.query.filter_by(office_id=office_id).first()
    if s:
        s.low_stock_threshold = threshold
    else:
        db.session.add(OfficeSetting(office_id=office_id, low_stock_threshold=threshold))

    db.session.commit()
    flash('Settings saved.', 'success')
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


@app.route('/api/add_contact', methods=['POST'])
@login_required
def add_contact():
    office_id = request.form.get('office_id', type=int)
    method    = request.form.get('method', '')
    label     = request.form.get('label', '').strip()
    email     = request.form.get('email', '').strip() or None
    token     = request.form.get('telegram_bot_token', '').strip() or None
    chat_id   = request.form.get('telegram_chat_id', '').strip() or None

    if method not in ('Email', 'Telegram', 'Both'):
        flash('Select a valid contact method.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='settings'))

    contact = Contact(method=method, label=label, email=email,
                       telegram_bot_token=token, telegram_chat_id=chat_id)
    db.session.add(contact)
    db.session.flush()

    # Create disabled-by-default settings for all offices
    for office in Office.query.all():
        db.session.add(OfficeContactSetting(
            office_id=office.id, contact_id=contact.id, notifications_enabled=False
        ))

    db.session.commit()
    flash(f'Contact "{label or method}" added.', 'success')
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


@app.route('/api/toggle_contact', methods=['POST'])
@login_required
def toggle_contact():
    office_id  = request.form.get('office_id', type=int)
    contact_id = request.form.get('contact_id', type=int)

    ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if ocs:
        ocs.notifications_enabled = not ocs.notifications_enabled
    else:
        db.session.add(OfficeContactSetting(
            office_id=office_id, contact_id=contact_id, notifications_enabled=True
        ))
    db.session.commit()
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


@app.route('/api/delete_contact', methods=['POST'])
@login_required
def delete_contact():
    office_id  = request.form.get('office_id', type=int)
    contact_id = request.form.get('contact_id', type=int)
    contact    = Contact.query.get_or_404(contact_id)
    db.session.delete(contact)
    db.session.commit()
    flash('Contact deleted.', 'success')
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


# ── Low-Stock Check Endpoint ──────────────────────────────────────────────────

@app.route('/api/check_low_stock')
def check_low_stock():
    """
    Check every office for low stock and dispatch notifications where needed.

    "Once per dip" logic per office:
      • If stock drops AT OR BELOW the threshold AND the office is NOT already flagged
        → send alerts, flag as low.
      • If stock recovers ABOVE the threshold AND the office IS flagged
        → clear the flag (next dip will alert again).
      • If already flagged (still low) → do nothing (no duplicate alerts).

    Call this endpoint from a cron job, Render cron, or an uptime monitor.
    Protect with ?token=<CHECK_TOKEN> (set CHECK_TOKEN env var; optional in dev).
    """
    expected = os.environ.get('CHECK_TOKEN', '')
    provided = request.args.get('token', '')
    if expected and provided != expected:
        abort(403)

    results = [_check_office(office) for office in Office.query.all()]
    return jsonify({'results': results})


def _check_office(office):
    settings = OfficeSetting.query.filter_by(office_id=office.id).first()
    threshold = settings.low_stock_threshold if settings else 3

    state = OfficeAlertState.query.filter_by(office_id=office.id).first()
    if not state:
        state = OfficeAlertState(office_id=office.id, is_currently_low=False)
        db.session.add(state)
        db.session.flush()

    lowest, bottleneck, product_name = calculate_lowest_buildable(office.id)
    is_low = lowest <= threshold

    if is_low and not state.is_currently_low:
        # New dip — notify all enabled contacts for this office
        contacts = (
            db.session.query(Contact)
            .join(OfficeContactSetting, OfficeContactSetting.contact_id == Contact.id)
            .filter(
                OfficeContactSetting.office_id == office.id,
                OfficeContactSetting.notifications_enabled == True,
            )
            .all()
        )
        for contact in contacts:
            _dispatch(contact, office.name, lowest, bottleneck, product_name)

        state.is_currently_low   = True
        state.last_notified_at   = datetime.now(timezone.utc)
        db.session.commit()

        return {'office': office.name, 'action': 'notified', 'lowest': lowest,
                'bottleneck': bottleneck, 'sent_to': [c.label or c.method for c in contacts]}

    elif not is_low and state.is_currently_low:
        # Stock recovered — reset flag so the next dip triggers again
        state.is_currently_low = False
        db.session.commit()
        return {'office': office.name, 'action': 'reset', 'lowest': lowest}

    else:
        status = 'already_alerted' if is_low else 'ok'
        return {'office': office.name, 'action': status, 'lowest': lowest}


# ── Utilities ─────────────────────────────────────────────────────────────────

def _fmt(n):
    """Format a float nicely: show integer if whole, else 1 decimal."""
    return str(int(n)) if n == int(n) else f'{n:.1f}'


# Make _fmt available in all Jinja2 templates
app.jinja_env.globals['fmt'] = _fmt


# ── DB Seed ───────────────────────────────────────────────────────────────────

def seed_database():
    """Ensure base data exists. Safe to run on every startup."""
    office_names = ['Rozet Office', 'Recluse Office']
    offices = []
    for name in office_names:
        office = Office.query.filter_by(name=name).first()
        if not office:
            office = Office(name=name)
            db.session.add(office)
        offices.append(office)
    db.session.flush()

    parts = []
    for name in SEED_PARTS:
        part = Part.query.filter_by(name=name).first()
        if not part:
            unit = 'inches' if 'Shrink Tube' in name else 'units'
            part = Part(name=name, unit=unit)
            db.session.add(part)
        parts.append(part)
    db.session.flush()

    for office in offices:
        for part in parts:
            if not Inventory.query.filter_by(office_id=office.id, part_id=part.id).first():
                db.session.add(Inventory(office_id=office.id, part_id=part.id, quantity=0.0))
        if not OfficeSetting.query.filter_by(office_id=office.id).first():
            db.session.add(OfficeSetting(office_id=office.id, low_stock_threshold=3))
        if not OfficeAlertState.query.filter_by(office_id=office.id).first():
            db.session.add(OfficeAlertState(office_id=office.id, is_currently_low=False))

    db.session.commit()


# ── Startup ───────────────────────────────────────────────────────────────────

with app.app_context():
    db.create_all()
    seed_database()


if __name__ == '__main__':
    debug = os.environ.get('FLASK_DEBUG', 'false').lower() == 'true'
    app.run(debug=debug, host='0.0.0.0', port=int(os.environ.get('PORT', 5000)))
