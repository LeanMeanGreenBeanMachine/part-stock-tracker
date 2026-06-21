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
            "3 Pin Connectors": 1,
            "Full Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
            "Large Cellophane Bags": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "2_foot_cable.png",
    },
    "4 Foot Cable": {
        "used_parts": {
            "Terminals": 3,
            "Wire Seals": 3,
            "3 Pin Connectors": 1,
            "Full Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
            "Large Cellophane Bags": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "4_foot_cable.png",
    },
    "Short Cable": {
        "used_parts": {
            "Terminals": 3,
            "Wire Seals": 3,
            "3 Pin Connectors": 1,
            "Short Cables": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 1.5,
            "Large Shrink Tube": 1,
            "Large Cellophane Bags": 1,
        },
        "contains": ["Black Wire", "Red Wire", "Solder"],
        "image": "short_cable.png",
    },
    "Rear Box": {
        "used_parts": {
            "Terminals": 3,
            "3 Pin Connectors": 1,
            "Audio Jacks": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 0.5,
            "Box Lids": 1,
            "Rear Boxes": 1,
            "Small Cellophane Bags": 1,
        },
        "contains": ["Black Wire", "Red Wire", "UV Resin", "Thread Locker", "Solder"],
        "image": "rear_box.png",
    },
    "Front Box": {
        "used_parts": {
            "Terminals": 3,
            "3 Pin Connectors": 1,
            "Audio Jacks": 1,
            "Envelopes": 1,
            "Small Shrink Tube": 0.5,
            "Box Lids": 1,
            "Front Boxes": 1,
            "Small Cellophane Bags": 1,
        },
        "contains": ["Black Wire", "Red Wire", "UV Resin", "Thread Locker", "Solder"],
        "image": "front_box.png",
    },
    "Output Jack": {
        "used_parts": {
            "8 Pin Connectors":  1,
            "Terminals":         3,
            "Audio Jacks":       1,
            "Small Shrink Tube": 1.25,
            "Large Shrink Tube": 0.25,
            "Large Cellophane Bags": 1,
        },
        "contains": ["Red Wire", "Black Wire", "Mesh Wire Loom", "Solder"],
        "image": "output_jack.png",
    },
    "Charge Box": {
        "used_parts": {
            "3 Pin Connectors":  1,
            "Audio Jacks":       1,
            "Terminals":         5,
            "USB Charge Boards": 1,
            "4 Pin Connectors":  1,
            "Small Shrink Tube": 0.5,
            "Charge Box Lids":      1,
            "Charge Boxes":         1,
            "Long Cellophane Bags": 1,
        },
        "contains": ["Blue Wire", "Yellow Wire", "Green Wire", "Black Wire", "Red Wire", "UV Resin", "Mesh Wire Loom"],
        "image": "charge_box.png",
    },
}

# Part name → static image filename
PART_IMAGES = {
    "Terminals":             "terminals.png",
    "Wire Seals":            "wire_seals.png",
    "Audio Jacks":           "aux_ports.png",
    "3 Pin Connectors":      "connectors.png",
    "8 Pin Connectors":      "8_pin_connectors.png",
    "Box Lids":              "box_lids.png",
    "Rear Boxes":            "rear_boxes.png",
    "Front Boxes":           "front_boxes.png",
    "Small Shrink Tube":     "small_shrink_tube.png",
    "Large Shrink Tube":     "large_shrink_tube.png",
    "Envelopes":             "envelopes.png",
    "Full Cables":           "full_cables.png",
    "Short Cables":          "short_cables.png",
    "Audio Jack Nuts":       "aux_port_nuts.png",
    "Large Cellophane Bags": "large_bags.png",
    "Small Cellophane Bags": "small_bags.png",
    "Long Cellophane Bags":  "long_bags.png",
    "4 Pin Connectors":      "4_pin_connectors.png",
    "USB Charge Boards":     "usb_charge_board.png",
    "Charge Box Lids":       "charge_box_lids.png",
    "Charge Boxes":          "charge_boxes.png",
    "2 Foot Cable [Ready]":  "2_foot_cable.png",
    "4 Foot Cable [Ready]":  "4_foot_cable.png",
    "Short Cable [Ready]":   "short_cable.png",
    "Rear Box [Ready]":      "rear_box.png",
    "Front Box [Ready]":     "front_box.png",
    "Output Jack [Ready]":   "output_jack.png",
    "Charge Box [Ready]":    "charge_box.png",
}

# Maps product name → the Part name that holds its pre-made finished-goods stock
PRODUCT_STOCK_PARTS = {
    "2 Foot Cable": "2 Foot Cable [Ready]",
    "4 Foot Cable": "4 Foot Cable [Ready]",
    "Short Cable":  "Short Cable [Ready]",
    "Rear Box":     "Rear Box [Ready]",
    "Front Box":    "Front Box [Ready]",
    "Output Jack":  "Output Jack [Ready]",
    "Charge Box":   "Charge Box [Ready]",
}

SEED_PARTS = list(PART_IMAGES.keys())

_CHART_COLORS = [
    '#39ff14', '#00cfff', '#ff4444', '#ffaa00', '#cc00ff',
    '#00ff88', '#ff6eb4', '#ffe100', '#4fc3f7', '#a5d6a7',
    '#ff8f00', '#b0bec5', '#f48fb1', '#80cbc4', '#ce93d8',
    '#ef9a9a', '#80deea', '#c5e1a5',
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
    struck       = db.Column(db.Boolean, default=False)   # True when reversed via "Strike From Log"
    used_premade = db.Column(db.Boolean, default=False)   # True when pre-made stock was used (not BOM parts)


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
    threshold             = db.Column(db.Integer, default=3)
    advanced_mode         = db.Column(db.Boolean, default=False)
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


class ContactAlertState(db.Model):
    __tablename__ = 'contact_alert_states'
    id               = db.Column(db.Integer, primary_key=True)
    office_id        = db.Column(db.Integer, db.ForeignKey('offices.id',  ondelete='CASCADE'), nullable=False)
    contact_id       = db.Column(db.Integer, db.ForeignKey('contacts.id', ondelete='CASCADE'), nullable=False)
    is_currently_low = db.Column(db.Boolean, default=False)
    last_notified_at = db.Column(db.DateTime)
    __table_args__ = (db.UniqueConstraint('office_id', 'contact_id'),)


class PartThreshold(db.Model):
    __tablename__ = 'part_thresholds'
    id               = db.Column(db.Integer, primary_key=True)
    office_id        = db.Column(db.Integer, db.ForeignKey('offices.id',  ondelete='CASCADE'), nullable=False)
    contact_id       = db.Column(db.Integer, db.ForeignKey('contacts.id', ondelete='CASCADE'), nullable=False)
    part_id          = db.Column(db.Integer, db.ForeignKey('parts.id',    ondelete='CASCADE'), nullable=False)
    threshold        = db.Column(db.Integer, default=0)
    is_currently_low = db.Column(db.Boolean, default=False)
    __table_args__ = (db.UniqueConstraint('office_id', 'contact_id', 'part_id'),)


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


def get_premade_stock(office_id, product_name):
    """Return the quantity of pre-made finished-goods stock for a product at an office."""
    part_name = PRODUCT_STOCK_PARTS.get(product_name)
    if not part_name:
        return 0
    part = Part.query.filter_by(name=part_name).first()
    if not part:
        return 0
    inv = Inventory.query.filter_by(office_id=office_id, part_id=part.id).first()
    return inv.quantity if inv else 0


def calculate_lowest_buildable(office_id):
    """
    Return (lowest_buildable, bottleneck_part, worst_product_name) across
    all 5 products for the given office. Includes pre-made stock in the count.
    """
    inv = get_inventory_map(office_id)
    results = []
    for name in PRODUCTS:
        from_parts, bottleneck = calculate_buildable(inv, name)
        premade = get_premade_stock(office_id, name)
        total = from_parts + int(premade)
        results.append((total, bottleneck, name))
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
        premade = get_premade_stock(office_id, name)
        product_data[name] = {**info, 'buildable': buildable, 'bottleneck': bottleneck, 'premade': premade}

    stock_part_names = set(PRODUCT_STOCK_PARTS.values())

    return render_template(
        'partials/main_menu.html',
        office=office,
        product_data=product_data,
        inventory_map=inventory_map,
        parts=parts,
        part_images=PART_IMAGES,
        stock_part_names=stock_part_names,
    )


def _get_part_steps():
    """Return {part_name: smallest qty used in any BOM} for spinner step sizing."""
    steps = {}
    for product in PRODUCTS.values():
        for part_name, qty in product.get('used_parts', {}).items():
            if part_name not in steps or qty < steps[part_name]:
                steps[part_name] = qty
    return steps


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
        stock_part_names=set(PRODUCT_STOCK_PARTS.values()),
        part_steps=_get_part_steps(),
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
    parts      = Part.query.order_by(Part.name).all()

    ocs_map = {}
    for c in contacts:
        ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=c.id).first()
        ocs_map[c.id] = ocs

    part_thresholds_map = {}
    for c in contacts:
        thresholds = PartThreshold.query.filter_by(office_id=office_id, contact_id=c.id).all()
        part_thresholds_map[c.id] = {pt.part_id: pt.threshold for pt in thresholds}

    lowest_buildable, bottleneck, _ = calculate_lowest_buildable(office_id)
    parts_for_js = [{'id': p.id, 'name': p.name} for p in parts]

    return render_template(
        'partials/settings.html',
        office=office,
        settings=settings,
        contacts=contacts,
        ocs_map=ocs_map,
        parts=parts,
        parts_for_js=parts_for_js,
        part_thresholds_map=part_thresholds_map,
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
    """Deduct from pre-made stock first; fall back to BOM parts. Records a ProductLog."""
    office_id    = request.form.get('office_id', type=int)
    product_name = request.form.get('product_name', '').strip()

    if product_name not in PRODUCTS:
        flash('Unknown product.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='main_menu'))

    bom           = PRODUCTS[product_name]['used_parts']
    inventory_map = get_inventory_map(office_id)
    premade_qty   = get_premade_stock(office_id, product_name)
    buildable, bottleneck = calculate_buildable(inventory_map, product_name)

    if premade_qty < 1 and buildable < 1:
        flash(f'Not enough stock to build "{product_name}". Bottleneck: {bottleneck or "N/A"}.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='main_menu'))

    try:
        if premade_qty >= 1:
            stock_part_name = PRODUCT_STOCK_PARTS[product_name]
            stock_part = Part.query.filter_by(name=stock_part_name).first()
            stock_inv  = _get_or_create_inv(office_id, stock_part.id)
            stock_inv.quantity = max(0.0, stock_inv.quantity - 1)
            _record_inventory_change(office_id, stock_part_name, 'order_log', 1,
                                     stock_inv.quantity, note=f'Pre-made: {product_name}')
            db.session.add(ProductLog(office_id=office_id, product_name=product_name, used_premade=True))
        else:
            for part_name, amount in bom.items():
                part = Part.query.filter_by(name=part_name).first()
                if not part:
                    continue
                inv = _get_or_create_inv(office_id, part.id)
                inv.quantity = max(0.0, inv.quantity - amount)
                _record_inventory_change(office_id, part_name, 'order_log', amount,
                                         inv.quantity, note=f'Used for: {product_name}')
            db.session.add(ProductLog(office_id=office_id, product_name=product_name, used_premade=False))

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
        if log.used_premade:
            stock_part_name = PRODUCT_STOCK_PARTS.get(product_name)
            if stock_part_name:
                stock_part = Part.query.filter_by(name=stock_part_name).first()
                if stock_part:
                    inv = _get_or_create_inv(office_id, stock_part.id)
                    inv.quantity += 1
                    _record_inventory_change(office_id, stock_part_name, 'order_strike', 1,
                                             inv.quantity, note=f'Strike: pre-made {product_name} log #{log_id}')
        else:
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


@app.route('/api/strike_inventory_log/<int:log_id>', methods=['POST'])
@login_required
def strike_inventory_log(log_id):
    """Delete an inventory log entry and reverse its effect on stock."""
    log = InventoryLog.query.get_or_404(log_id)
    office_id = log.office_id
    part_name = log.part_name

    try:
        part = Part.query.filter_by(name=part_name).first()
        if part:
            inv = _get_or_create_inv(office_id, part.id)
            if log.change_type in ('add', 'order_strike'):
                inv.quantity = max(0.0, inv.quantity - log.amount)
            elif log.change_type in ('subtract', 'order_log'):
                inv.quantity += log.amount
            elif log.change_type == 'transfer':
                if log.note and 'Transfer to' in log.note:
                    inv.quantity += log.amount
                else:
                    inv.quantity = max(0.0, inv.quantity - log.amount)
        db.session.delete(log)
        db.session.commit()
        flash(f'Log entry removed and inventory adjusted for "{part_name}".', 'success')
    except Exception as exc:
        db.session.rollback()
        app.logger.error('strike_inventory_log failed: %s', exc)
        flash('Strike failed. Please try again.', 'danger')

    return redirect(url_for('dashboard', office_id=office_id, section='inventory_history'))


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


@app.route('/api/save_contact_threshold', methods=['POST'])
@login_required
def save_contact_threshold():
    office_id  = request.form.get('office_id', type=int)
    contact_id = request.form.get('contact_id', type=int)
    threshold  = request.form.get('threshold', type=int)

    if threshold is None or threshold < 1:
        flash('Threshold must be at least 1.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='settings'))

    ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if not ocs:
        ocs = OfficeContactSetting(office_id=office_id, contact_id=contact_id,
                                    notifications_enabled=False, threshold=threshold)
        db.session.add(ocs)
    else:
        ocs.threshold = threshold

    # Reset alert state so the updated threshold can trigger a fresh notification
    state = ContactAlertState.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if state:
        state.is_currently_low = False

    db.session.commit()
    flash('Threshold saved.', 'success')
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


@app.route('/api/toggle_advanced_mode', methods=['POST'])
@login_required
def toggle_advanced_mode():
    office_id  = request.form.get('office_id', type=int)
    contact_id = request.form.get('contact_id', type=int)

    ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if ocs:
        ocs.advanced_mode = not ocs.advanced_mode
        db.session.commit()
    return redirect(url_for('dashboard', office_id=office_id, section='settings'))


@app.route('/api/save_advanced_thresholds', methods=['POST'])
@login_required
def save_advanced_thresholds():
    office_id  = request.form.get('office_id', type=int)
    contact_id = request.form.get('contact_id', type=int)

    ocs = OfficeContactSetting.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if not ocs:
        flash('Contact setting not found.', 'danger')
        return redirect(url_for('dashboard', office_id=office_id, section='settings'))

    ocs.advanced_mode = True
    parts = Part.query.all()
    for part in parts:
        threshold = request.form.get(f'part_{part.id}', type=int) or 0
        threshold = max(0, threshold)
        pt = PartThreshold.query.filter_by(
            office_id=office_id, contact_id=contact_id, part_id=part.id
        ).first()
        if pt:
            pt.threshold = threshold
        else:
            db.session.add(PartThreshold(
                office_id=office_id, contact_id=contact_id,
                part_id=part.id, threshold=threshold
            ))

    db.session.commit()
    flash('Advanced thresholds saved.', 'success')
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
        office_settings = OfficeSetting.query.filter_by(office_id=office.id).first()
        default_threshold = office_settings.low_stock_threshold if office_settings else 3
        db.session.add(OfficeContactSetting(
            office_id=office.id, contact_id=contact.id,
            notifications_enabled=False, threshold=default_threshold
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


def _get_or_create_contact_alert_state(office_id, contact_id):
    state = ContactAlertState.query.filter_by(office_id=office_id, contact_id=contact_id).first()
    if not state:
        state = ContactAlertState(office_id=office_id, contact_id=contact_id, is_currently_low=False)
        db.session.add(state)
        db.session.flush()
    return state


def _dispatch_advanced(contact, office_name, newly_low_parts):
    """Send a combined alert listing all parts that newly crossed their threshold."""
    lines = '\n'.join(
        f'  • {name} — {qty} remaining (threshold: {threshold})'
        for name, qty, threshold in newly_low_parts
    )
    subject = f'[Vulcan] Low Parts Alert — {office_name}'
    body = (
        f'⚠️  Low parts alert for {office_name}.\n\n'
        f'Parts below threshold:\n{lines}\n\n'
        f'Please reorder soon.'
    )
    if contact.method in ('Email', 'Both') and contact.email:
        _send_email(contact, subject, body)
    if contact.method in ('Telegram', 'Both') and contact.telegram_bot_token:
        _send_telegram(contact, body)


def _check_contact_advanced(office, contact, inventory_map):
    """Per-part threshold check for a contact with advanced mode enabled."""
    part_thresholds = (
        PartThreshold.query
        .filter_by(office_id=office.id, contact_id=contact.id)
        .filter(PartThreshold.threshold > 0)
        .all()
    )
    newly_low = []
    for pt in part_thresholds:
        part = Part.query.get(pt.part_id)
        if not part:
            continue
        qty = inventory_map.get(part.name, 0)
        is_low = qty <= pt.threshold
        if is_low and not pt.is_currently_low:
            newly_low.append((part.name, qty, pt.threshold))
            pt.is_currently_low = True
        elif not is_low and pt.is_currently_low:
            pt.is_currently_low = False
    if newly_low:
        _dispatch_advanced(contact, office.name, newly_low)
    return newly_low


def _check_office(office):
    """
    Check each enabled contact for this office independently.
    Simple mode: contact's personal threshold vs. lowest buildable + pre-made stock.
    Advanced mode: per-part raw quantity thresholds, each part alerted independently.
    """
    inventory_map = get_inventory_map(office.id)
    lowest, bottleneck, product_name = calculate_lowest_buildable(office.id)

    enabled_ocs = OfficeContactSetting.query.filter_by(
        office_id=office.id, notifications_enabled=True
    ).all()

    results = []
    for ocs in enabled_ocs:
        contact = Contact.query.get(ocs.contact_id)
        if not contact:
            continue

        if ocs.advanced_mode:
            newly_low = _check_contact_advanced(office, contact, inventory_map)
            results.append({
                'contact': contact.label or contact.method,
                'mode': 'advanced',
                'newly_alerted_parts': [p[0] for p in newly_low],
            })
        else:
            threshold = ocs.threshold
            state = _get_or_create_contact_alert_state(office.id, contact.id)
            is_low = lowest <= threshold

            if is_low and not state.is_currently_low:
                _dispatch(contact, office.name, lowest, bottleneck, product_name)
                state.is_currently_low = True
                state.last_notified_at = datetime.now(timezone.utc)
                results.append({'contact': contact.label or contact.method, 'action': 'notified',
                                 'lowest': lowest, 'threshold': threshold})
            elif not is_low and state.is_currently_low:
                state.is_currently_low = False
                results.append({'contact': contact.label or contact.method, 'action': 'reset'})
            else:
                results.append({'contact': contact.label or contact.method,
                                 'action': 'already_alerted' if is_low else 'ok'})

    db.session.commit()
    return {'office': office.name, 'lowest': lowest, 'results': results}


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


# ── DB Migration ──────────────────────────────────────────────────────────────

def _migrate_database():
    """Add columns that may be absent from existing databases (safe to re-run)."""
    migrations = [
        ("product_logs",            "used_premade",  "BOOLEAN DEFAULT 0"),
        ("office_contact_settings", "threshold",     "INTEGER DEFAULT 3"),
        ("office_contact_settings", "advanced_mode", "BOOLEAN DEFAULT 0"),
    ]
    part_renames = [
        ("Aux Ports",      "Audio Jacks"),
        ("Aux Port Nuts",  "Audio Jack Nuts"),
        ("Connectors",     "3 Pin Connectors"),
    ]
    with db.engine.connect() as conn:
        for table, column, col_def in migrations:
            try:
                conn.execute(db.text(f"ALTER TABLE {table} ADD COLUMN {column} {col_def}"))
                conn.commit()
            except Exception:
                pass  # Column already exists; ignore

        for old_name, new_name in part_renames:
            conn.execute(db.text("UPDATE parts SET name=:new WHERE name=:old"),
                         {"new": new_name, "old": old_name})
            conn.execute(db.text("UPDATE inventory_logs SET part_name=:new WHERE part_name=:old"),
                         {"new": new_name, "old": old_name})
        conn.commit()


# ── Startup ───────────────────────────────────────────────────────────────────

with app.app_context():
    db.create_all()
    _migrate_database()
    seed_database()


if __name__ == '__main__':
    debug = os.environ.get('FLASK_DEBUG', 'false').lower() == 'true'
    app.run(debug=debug, host='0.0.0.0', port=int(os.environ.get('PORT', 5000)))
