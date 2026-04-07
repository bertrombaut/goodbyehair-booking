<?php
/**
 * Plugin Name: GoodByeHair Booking
 */

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'gbh_create_tables');

function gbh_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Klantentabel
    $klanten = $wpdb->prefix . 'gbh_klanten';
    $sql1 = "CREATE TABLE IF NOT EXISTS $klanten (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        naam varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        telefoon varchar(30) NOT NULL,
        aangemaakt datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) $charset;";

    // Afsprakentabel
    $bookings = $wpdb->prefix . 'gbh_bookings';
    $sql2 = "CREATE TABLE IF NOT EXISTS $bookings (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        klant_id bigint(20) DEFAULT NULL,
        naam varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        telefoon varchar(30) NOT NULL,
        datum date NOT NULL,
        tijd time NOT NULL,
        behandelingen text NOT NULL,
        behandeltijd int NOT NULL,
        prijs decimal(10,2) NOT NULL,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);

    // Voeg klant_id kolom toe als die nog niet bestaat (voor bestaande installaties)
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $bookings LIKE 'klant_id'");
    if (empty($columns)) {
        $wpdb->query("ALTER TABLE $bookings ADD COLUMN klant_id bigint(20) DEFAULT NULL AFTER id");
    }

    // Sla standaard medewerker wachtwoord op
    if (!get_option('gbh_medewerker_user')) {
        update_option('gbh_medewerker_user', 'medewerker');
        update_option('gbh_medewerker_pass', password_hash('welkom123', PASSWORD_DEFAULT));
    }
}

class GBH_Booking {

    public function __construct() {
        add_shortcode('gbh_booking', [$this, 'render']);
        add_shortcode('gbh_medewerker', [$this, 'render_medewerker']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_gbh_save_booking', [$this, 'save_booking']);
        add_action('wp_ajax_nopriv_gbh_save_booking', [$this, 'save_booking']);
        add_action('wp_ajax_gbh_zoek_klant', [$this, 'zoek_klant']);
        add_action('wp_ajax_nopriv_gbh_zoek_klant', [$this, 'zoek_klant']);
        add_action('wp_ajax_gbh_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_gbh_login', [$this, 'handle_login']);
        add_action('wp_ajax_gbh_logout', [$this, 'handle_logout']);
        add_action('wp_ajax_nopriv_gbh_logout', [$this, 'handle_logout']);
        add_action('wp_ajax_gbh_klant_opslaan', [$this, 'klant_opslaan']);
        add_action('wp_ajax_nopriv_gbh_klant_opslaan', [$this, 'klant_opslaan']);
        add_action('wp_ajax_gbh_klant_verwijderen', [$this, 'klant_verwijderen']);
        add_action('wp_ajax_nopriv_gbh_klant_verwijderen', [$this, 'klant_verwijderen']);
        add_action('gbh_stuur_herinnering', [$this, 'stuur_herinnering'], 10, 6);
        add_action('admin_post_gbh_annuleer', [$this, 'annuleer_boeking']);
    }

    // -------------------------
    // KLANT ZOEKEN VIA AJAX
    // -------------------------
    public function zoek_klant() {
        global $wpdb;
        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email) wp_send_json_error('Geen email');
        $klant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_klanten WHERE email = %s",
            $email
        ));
        if ($klant) {
            wp_send_json_success([
                'gevonden' => true,
                'naam'     => $klant->naam,
                'telefoon' => $klant->telefoon,
            ]);
        } else {
            wp_send_json_success(['gevonden' => false]);
        }
    }

    // -------------------------
    // LOGIN / LOGOUT (eigen systeem)
    // -------------------------
    private function gbh_is_ingelogd() {
        if (!isset($_COOKIE['gbh_medewerker'])) return false;
        $cookie = $_COOKIE['gbh_medewerker'];
        $token = get_option('gbh_medewerker_token', '');
        return $cookie === $token;
    }

    public function handle_login() {
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $opgeslagen_user = get_option('gbh_medewerker_user', '');
        $opgeslagen_pass = get_option('gbh_medewerker_pass', '');
        if ($username !== $opgeslagen_user || !password_verify($password, $opgeslagen_pass)) {
            wp_send_json_error('Gebruikersnaam of wachtwoord onjuist.');
        }
       $token = get_option('gbh_medewerker_token', '');
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            update_option('gbh_medewerker_token', $token);
        }
        setcookie('gbh_medewerker', $token, time() + (8 * 60 * 60), '/', '', false, true);
        wp_send_json_success('Ingelogd');
    }

    public function handle_logout() {
        setcookie('gbh_medewerker', '', time() - 3600, '/', '', false, true);
        wp_send_json_success('Uitgelogd');
    }

    // -------------------------
    // KLANT OPSLAAN (medewerker)
    // -------------------------
    public function klant_opslaan() {
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id       = intval($_POST['id'] ?? 0);
        $naam     = sanitize_text_field($_POST['naam'] ?? '');
        $email    = sanitize_email($_POST['email'] ?? '');
        $telefoon = sanitize_text_field($_POST['telefoon'] ?? '');
        if (!$naam || !$email) wp_send_json_error('Vul naam en email in.');
        if ($id) {
            $wpdb->update($wpdb->prefix . 'gbh_klanten', compact('naam', 'email', 'telefoon'), ['id' => $id]);
        } else {
            $wpdb->insert($wpdb->prefix . 'gbh_klanten', compact('naam', 'email', 'telefoon'));
        }
        wp_send_json_success('Opgeslagen.');
    }

    // -------------------------
    // KLANT VERWIJDEREN (medewerker)
    // -------------------------
    public function klant_verwijderen() {
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gbh_klanten', ['id' => $id]);
        wp_send_json_success('Verwijderd.');
    }

    // -------------------------
    // FRONTEND MEDEWERKER PANEL
    // -------------------------
    public function render_medewerker() {
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        ob_start();
        echo '<div id="gbh-medewerker-wrap">';

        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            // Loginformulier
            echo '<div id="gbh-login-form" style="max-width:360px;margin:0 auto;padding:24px;border:2px solid #7d3c98;border-radius:12px;background:#faf5ff;">';
            echo '<h2 style="color:#7d3c98;margin-top:0;">Medewerker login</h2>';
            echo '<div id="gbh-login-error" style="color:#c62828;margin-bottom:10px;display:none;"></div>';
            echo '<label style="display:block;margin-bottom:10px;">Gebruikersnaam<br><input type="text" id="gbh-login-user" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"></label>';
            echo '<label style="display:block;margin-bottom:16px;">Wachtwoord<br><input type="password" id="gbh-login-pass" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"></label>';
            echo '<button type="button" id="gbh-login-btn" style="width:100%;padding:12px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;font-size:15px;font-weight:600;">Inloggen</button>';
            echo '</div>';
            echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("gbh-login-btn").addEventListener("click", function() {
        const user = document.getElementById("gbh-login-user").value;
        const pass = document.getElementById("gbh-login-pass").value;
        const error = document.getElementById("gbh-login-error");
        const data = new FormData();
        data.append("action", "gbh_login");
        data.append("username", user);
        data.append("password", pass);
       fetch("' . $ajax_url . '", { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.location.href = window.location.href;
            } else {
                error.style.display = "block";
                error.textContent = res.data;
            }
        });
    });
    document.getElementById("gbh-login-pass").addEventListener("keydown", function(e) {
        if (e.key === "Enter") document.getElementById("gbh-login-btn").click();
    });
});
</script>';
        } else {
            // Klantenbeheer
            global $wpdb;
            $klanten = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_klanten ORDER BY naam ASC");
            $current_user = wp_get_current_user();

            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">';
            echo '<h2 style="color:#7d3c98;margin:0;">Klantenbeheer</h2>';
            echo '<div>';
            echo '<span style="margin-right:16px;font-size:14px;color:#666;">Ingelogd als: <strong>' . esc_html($current_user->display_name) . '</strong></span>';
            echo '<button type="button" id="gbh-logout-btn" style="padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Uitloggen</button>';
            echo '</div>';
            echo '</div>';

            // Nieuw klant formulier
            echo '<div style="margin-bottom:24px;padding:16px;border:2px solid #7d3c98;border-radius:12px;background:#faf5ff;">';
            echo '<h3 style="color:#7d3c98;margin-top:0;">Nieuwe klant toevoegen</h3>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<input type="text" id="gbh-nieuw-naam" placeholder="Naam" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
            echo '<input type="email" id="gbh-nieuw-email" placeholder="Email" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
            echo '<input type="tel" id="gbh-nieuw-telefoon" placeholder="Telefoon" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
            echo '<button type="button" id="gbh-nieuw-btn" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;font-weight:600;">Toevoegen</button>';
            echo '</div>';
            echo '<div id="gbh-nieuw-msg" style="margin-top:8px;font-size:14px;"></div>';
            echo '</div>';

            // Zoekbalk
            echo '<input type="text" id="gbh-zoek" placeholder="Zoek op naam of email..." style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-bottom:16px;box-sizing:border-box;">';

            // Klantenlijst
            echo '<div id="gbh-klanten-lijst">';
            if ($klanten) {
                foreach ($klanten as $k) {
                    echo '<div class="gbh-klant-rij" data-zoek="' . esc_attr(strtolower($k->naam . ' ' . $k->email)) . '" style="padding:14px;border:1px solid #ddd;border-radius:10px;margin-bottom:10px;background:#fff;">';
                    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">';
                    echo '<div>';
                    echo '<strong style="font-size:16px;">' . esc_html($k->naam) . '</strong><br>';
                    echo '<span style="color:#666;font-size:14px;">' . esc_html($k->email) . ' · ' . esc_html($k->telefoon) . '</span>';
                    echo '</div>';
                    echo '<div style="display:flex;gap:8px;">';
                    echo '<button type="button" class="gbh-edit-btn" data-id="' . esc_attr($k->id) . '" style="padding:6px 14px;border:1px solid #7d3c98;border-radius:8px;background:#fff;color:#7d3c98;cursor:pointer;">Bewerken</button>';
                    echo '<button type="button" class="gbh-del-btn" data-id="' . esc_attr($k->id) . '" style="padding:6px 14px;border:0;border-radius:8px;background:#c62828;color:#fff;cursor:pointer;">Verwijderen</button>';
                    echo '</div>';
                    echo '</div>';
                    // Bewerkformulier (verborgen)
                    echo '<div class="gbh-edit-form" id="gbh-edit-' . esc_attr($k->id) . '" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #eee;">';
                    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
                    echo '<input type="text" class="gbh-edit-naam" value="' . esc_attr($k->naam) . '" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
                    echo '<input type="email" class="gbh-edit-email" value="' . esc_attr($k->email) . '" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
                    echo '<input type="tel" class="gbh-edit-telefoon" value="' . esc_attr($k->telefoon) . '" style="flex:1;min-width:140px;padding:10px;border:1px solid #ccc;border-radius:8px;">';
                    echo '<button type="button" class="gbh-save-btn" data-id="' . esc_attr($k->id) . '" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;font-weight:600;">Opslaan</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<p style="color:#999;">Nog geen klanten gevonden.</p>';
            }
            echo '</div>';

            echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const ajaxUrl = "' . $ajax_url . '";

    document.getElementById("gbh-logout-btn").addEventListener("click", function() {
        const data = new FormData();
        data.append("action", "gbh_logout");
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(() => {
            document.cookie = "gbh_medewerker=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            location.reload();
        });
    });

    document.getElementById("gbh-zoek").addEventListener("input", function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll(".gbh-klant-rij").forEach(function(rij) {
            rij.style.display = rij.dataset.zoek.includes(q) ? "block" : "none";
        });
    });

    document.getElementById("gbh-nieuw-btn").addEventListener("click", function() {
        const naam = document.getElementById("gbh-nieuw-naam").value.trim();
        const email = document.getElementById("gbh-nieuw-email").value.trim();
        const telefoon = document.getElementById("gbh-nieuw-telefoon").value.trim();
        const msg = document.getElementById("gbh-nieuw-msg");
        if (!naam || !email) { msg.style.color = "#c62828"; msg.textContent = "Vul naam en email in."; return; }
        const data = new FormData();
        data.append("action", "gbh_klant_opslaan");
        data.append("naam", naam);
        data.append("email", email);
        data.append("telefoon", telefoon);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) { location.reload(); }
            else { msg.style.color = "#c62828"; msg.textContent = res.data; }
        });
    });

    document.querySelectorAll(".gbh-edit-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const id = btn.dataset.id;
            const form = document.getElementById("gbh-edit-" + id);
            form.style.display = form.style.display === "none" ? "block" : "none";
        });
    });

    document.querySelectorAll(".gbh-save-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const id = btn.dataset.id;
            const form = document.getElementById("gbh-edit-" + id);
            const naam = form.querySelector(".gbh-edit-naam").value.trim();
            const email = form.querySelector(".gbh-edit-email").value.trim();
            const telefoon = form.querySelector(".gbh-edit-telefoon").value.trim();
            const data = new FormData();
            data.append("action", "gbh_klant_opslaan");
            data.append("id", id);
            data.append("naam", naam);
            data.append("email", email);
            data.append("telefoon", telefoon);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); });
        });
    });

    document.querySelectorAll(".gbh-del-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            if (!confirm("Weet je zeker dat je deze klant wilt verwijderen?")) return;
            const data = new FormData();
            data.append("action", "gbh_klant_verwijderen");
            data.append("id", btn.dataset.id);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); });
        });
    });
});
</script>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    // -------------------------
    // BOOKING RENDER
    // -------------------------
    public function render() {

        global $wpdb;
        $table = $wpdb->prefix . 'gbh_bookings';
        $booked = $wpdb->get_results("SELECT datum, tijd, behandeltijd FROM $table", ARRAY_A);
        $bookings_list = [];
        foreach ($booked as $b) {
            $start_time = substr($b['tijd'], 0, 5);
            $start_ts = strtotime('1970-01-01 ' . $start_time);
            $duur = intval($b['behandeltijd']);
            $slots = ceil($duur / 15) + 1;
            for ($i = 0; $i < $slots; $i++) {
                $slot_ts = $start_ts + ($i * 15 * 60);
                $slot_time = date('H:i', $slot_ts);
                $bookings_list[] = $b['datum'] . ' ' . $slot_time;
            }
        }

        $treatments = [
            'Gezicht' => [
                ['name' => 'Bovenlip', 'time' => 15, 'price' => 19],
                ['name' => 'Kin', 'time' => 15, 'price' => 19],
                ['name' => 'Kaaklijn', 'time' => 15, 'price' => 35],
                ['name' => 'Nek', 'time' => 15, 'price' => 25],
                ['name' => 'Hals', 'time' => 15, 'price' => 25],
                ['name' => 'Wangen', 'time' => 15, 'price' => 19],
                ['name' => 'Gehele gezicht', 'time' => 20, 'price' => 75],
            ],
            'Lichaam' => [
                ['name' => 'Oksels', 'time' => 20, 'price' => 39],
                ['name' => 'Onderarm', 'time' => 15, 'price' => 49],
                ['name' => 'Bovenarm', 'time' => 15, 'price' => 49],
                ['name' => 'Armen geheel', 'time' => 30, 'price' => 89],
                ['name' => 'Borst', 'time' => 30, 'price' => 35],
                ['name' => 'Tepels rondom', 'time' => 15, 'price' => 19],
                ['name' => 'Buik', 'time' => 20, 'price' => 49],
                ['name' => 'Navelstrook', 'time' => 20, 'price' => 19],
                ['name' => 'Onderrug', 'time' => 20, 'price' => 49],
                ['name' => 'Bovenrug', 'time' => 20, 'price' => 49],
                ['name' => 'Rug geheel', 'time' => 30, 'price' => 89],
                ['name' => 'Bikinilijn klein', 'time' => 15, 'price' => 25],
                ['name' => 'Bikinilijn groot', 'time' => 20, 'price' => 55],
                ['name' => 'Onderbenen', 'time' => 20, 'price' => 65],
                ['name' => 'Bovenbenen', 'time' => 20, 'price' => 65],
                ['name' => 'Benen geheel', 'time' => 30, 'price' => 119],
            ]
        ];

        $ajax_url = esc_url(admin_url('admin-ajax.php'));

        ob_start();

        echo '<style>
.gbh-booking { width:100%; }
.gbh-columns { display:flex; gap:30px; align-items:flex-start; flex-wrap:wrap; }
.gbh-col { flex:1; min-width:200px; }
.gbh-col-summary { flex:0 0 auto; width:280px; }
@media(max-width:600px) {
    .gbh-columns { flex-direction:column; }
    .gbh-col-summary { width:100%; }
}
.gbh-treatment-label { display:flex; align-items:center; gap:8px; padding:10px 12px; margin-bottom:6px; border-radius:8px; cursor:pointer; transition:background 0.15s; font-size:18px !important; }
.gbh-treatment-label:hover { background:#f3e5f5; }
.gbh-treatment-label input { accent-color:#7d3c98; width:20px; height:20px; cursor:pointer; }
.gbh-price { margin-left:auto; color:#7d3c98; font-weight:600; white-space:nowrap; font-size:18px !important; }
.gbh-summary-box { padding:16px; border:2px solid #7d3c98; border-radius:12px; background:#faf5ff; position:fixed; width:240px; z-index:999; box-shadow:0 4px 16px rgba(0,0,0,0.15); }
.gbh-summary-box strong { color:#7d3c98; font-size:16px; }
.gbh-next-btn { display:block; width:100%; margin-top:14px; padding:12px; border:0; border-radius:8px; background:#7d3c98; color:#fff; cursor:pointer; font-size:15px; font-weight:600; }
.gbh-next-btn:hover { background:#6a2f82; }
h3.gbh-cat { color:#7d3c98; font-size:15px; margin:0 0 8px; border-bottom:2px solid #e8d5f5; padding-bottom:6px; }
.gbh-welkom { padding:10px 14px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; color:#2e7d32; font-weight:600; margin-bottom:12px; font-size:15px; display:none; }
@keyframes gbh-knipperen { 0%, 100% { opacity:1; } 50% { opacity:0; } }
@keyframes gbh-knipperen-tijd { 0%, 100% { opacity:1; } 50% { opacity:0; } }
</style>';

        echo '<div class="gbh-booking">';
        echo '<h2>Kies je behandeling</h2>';
        echo '<div class="gbh-columns">';

        foreach ($treatments as $category => $items) {
            echo '<div class="gbh-col">';
            echo '<h3 class="gbh-cat">' . esc_html($category) . '</h3>';
            foreach ($items as $t) {
                echo '<label class="gbh-treatment-label">';
                echo '<input type="checkbox" class="gbh-treatment" data-time="' . esc_attr($t['time']) . '" data-price="' . esc_attr($t['price']) . '"> ';
                echo '<span style="font-size:18px !important;">' . esc_html($t['name']) . ' <span style="color:#999;font-size:15px !important;">(' . esc_html($t['time']) . ' min)</span></span>';
                echo '<span class="gbh-price" style="font-size:18px !important;">€' . esc_html($t['price']) . '</span>';
                echo '</label>';
            }
            echo '</div>';
        }

        echo '<div class="gbh-col-summary">';
        echo '<div id="gbh-summary-anchor"></div>';
        echo '<div class="gbh-summary-box" id="gbh-summary">';
        echo '<strong>Overzicht</strong><br><br>';
        echo '<div style="font-size:14px;margin-bottom:4px;">Behandeltijd: <span id="gbh-total-time">0</span> min</div>';
        echo '<div style="font-size:14px;">Totaal: <strong>€<span id="gbh-total-price">0,00</span></strong></div>';
        echo '<button type="button" id="gbh-next-step" class="gbh-next-btn">Kies een datum/tijd →</button>';
        echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    function positionSummary() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        box.style.top = Math.max(20, rect.top + scrollTop - scrollTop + 20) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    }
    positionSummary();
   window.addEventListener("scroll", function() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        const windowHeight = window.innerHeight;
        const boxHeight = box.offsetHeight;
        const centeredTop = (windowHeight / 2) - (boxHeight / 2);
        box.style.top = Math.max(20, centeredTop) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    });
    window.addEventListener("resize", positionSummary);
});
</script>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Stap 2: kalender
        echo '<div id="gbh-step-2" style="display:none;margin-top:20px;">';
        echo '<div id="gbh-datum-header" style="display:table;text-align:center;margin:0 auto 12px auto;padding:10px 20px;background:#7d3c98;color:#fff;border-radius:8px;font-weight:700;font-size:18px;white-space:nowrap;animation:gbh-knipperen 2s step-start infinite;">Kies een datum</div>';
        echo '<div id="gbh-calendar" style="margin-bottom:20px;"></div>';
        echo '<div id="gbh-chosen-date" style="margin:0 0 12px 0;font-weight:600;"></div>';
        echo '<div id="gbh-times-header" style="display:none;text-align:center;margin:0 auto 12px auto;padding:10px 20px;background:#7d3c98;color:#fff;border-radius:8px;font-weight:700;font-size:18px;white-space:nowrap;">Kies een tijdstip</div>';
        echo '<div id="gbh-times"></div>';
        echo '<div id="gbh-chosen-time" style="margin-top:8px;margin-bottom:12px;font-weight:600;"></div>';
        echo '<input type="hidden" id="gbh-selected-date" value="">';
        echo '<input type="hidden" id="gbh-selected-time" value="">';
        echo '<div id="gbh-stap2-fout" style="display:none;margin-bottom:10px;padding:10px 14px;background:#fdecea;border:1px solid #f5c6cb;border-radius:8px;color:#c62828;font-weight:600;text-align:center;animation:gbh-knipperen 2s step-start infinite;"></div>';
        echo '<div id="gbh-step2-buttons" style="margin-top:16px;">';
        echo '<button type="button" id="gbh-back-to-step1" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-right:10px;">← Terug</button>';
        echo '<button type="button" id="gbh-next-to-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;transition:all 0.3s;">Volgende →</button>';
        echo '</div>';

        echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const calendar = document.getElementById("gbh-calendar");
    if (!calendar) return;
    const days = ' . json_encode(get_option('gbh_days', [])) . ';
    const times = ' . json_encode(get_option('gbh_times', [])) . ';
    const bookings = ' . json_encode($bookings_list) . ';
    const monthNames = ["Januari","Februari","Maart","April","Mei","Juni","Juli","Augustus","September","Oktober","November","December"];
    const dayNames = ["Ma","Di","Wo","Do","Vr","Za","Zo"];
    const map = ["zo","ma","di","wo","do","vr","za"];
    let year = new Date().getFullYear();
    let month = new Date().getMonth();
    let selectedDate = "";
    const today = new Date();
    today.setHours(0,0,0,0);

    function resetTimes() {
        document.getElementById("gbh-times").innerHTML = "";
        document.getElementById("gbh-times-header").style.display = "none";
        document.getElementById("gbh-selected-time").value = "";
        document.getElementById("gbh-chosen-date").textContent = "";
        document.getElementById("gbh-chosen-time").textContent = "";
        document.getElementById("gbh-selected-date").value = "";
        const datumHeader = document.getElementById("gbh-datum-header");
        datumHeader.style.background = "#7d3c98";
        datumHeader.style.color = "#fff";
        datumHeader.style.fontSize = "18px";
        datumHeader.style.padding = "12px 20px";
        const tijdHeader = document.getElementById("gbh-times-header");
        tijdHeader.style.background = "#7d3c98";
        tijdHeader.style.color = "#fff";
        tijdHeader.style.fontSize = "18px";
        tijdHeader.style.padding = "12px 20px";
        const volgendeBtn = document.getElementById("gbh-next-to-step3");
        volgendeBtn.style.background = "#7d3c98";
        volgendeBtn.style.fontSize = "";
        volgendeBtn.style.padding = "10px 18px";
        volgendeBtn.style.boxShadow = "";
        selectedDate = "";
    }

    function renderCalendar() {
        const firstDate = new Date(year, month, 1);
        let firstDay = firstDate.getDay();
        firstDay = firstDay === 0 ? 6 : firstDay - 1;
        const totalDays = new Date(year, month + 1, 0).getDate();
        let html = "";
        html += "<div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;\">";
        html += "<button type=\"button\" id=\"gbh-prev-month\" style=\"padding:8px 12px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;\">←</button>";
        html += "<strong style=\"font-size:18px;\">" + monthNames[month] + " " + year + "</strong>";
        html += "<button type=\"button\" id=\"gbh-next-month\" style=\"padding:8px 12px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;\">→</button>";
        html += "</div>";
        html += "<div style=\"display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;\">";
        dayNames.forEach(function (name) {
            html += "<div style=\"padding:8px;text-align:center;font-weight:600;\">" + name + "</div>";
        });
        html += "</div>";
        html += "<div style=\"display:grid;grid-template-columns:repeat(7,1fr);gap:6px;\">";
        for (let i = 0; i < firstDay; i++) { html += "<div></div>"; }
        for (let d = 1; d <= totalDays; d++) {
            const date = new Date(year, month, d);
            const dayKey = map[date.getDay()];
            const enabled = days.includes(dayKey);
            const isPast = date <= today;
            const isEnabled = enabled && !isPast;
            const monthValue = String(month + 1).padStart(2, "0");
            const dayValue = String(d).padStart(2, "0");
            const fullDate = year + "-" + monthValue + "-" + dayValue;
            const isSelected = selectedDate === fullDate;
            html += "<button type=\"button\" class=\"gbh-calendar-day\" data-date=\"" + fullDate + "\" " + (isEnabled ? "" : "disabled") + " style=\"padding:10px;border:1px solid " + (isSelected ? "#7d3c98" : "#ccc") + ";border-radius:6px;text-align:center;cursor:" + (isEnabled ? "pointer" : "not-allowed") + ";background:" + (isEnabled ? (isSelected ? "#7d3c98" : "#fff") : "#eee") + ";color:" + (isSelected ? "#fff" : "#000") + ";\">" + d + "</button>";
        }
        html += "</div>";
        calendar.innerHTML = html;
        const chosenDateText = document.getElementById("gbh-chosen-date");
        const selectedDateInput = document.getElementById("gbh-selected-date");
        document.querySelectorAll(".gbh-calendar-day").forEach(function (button) {
            button.addEventListener("click", function () {
                if (button.disabled) return;
                selectedDate = button.dataset.date;
                selectedDateInput.value = selectedDate;
                chosenDateText.textContent = "Gekozen datum: " + selectedDate;
                document.getElementById("gbh-stap2-fout").style.display = "none";
                document.getElementById("gbh-chosen-time").textContent = "";
                document.getElementById("gbh-selected-time").value = "";
                const tijdHeader = document.getElementById("gbh-times-header");
                tijdHeader.style.background = "#7d3c98";
                tijdHeader.style.color = "#fff";
                tijdHeader.style.fontSize = "18px";
                tijdHeader.style.padding = "12px 20px";
                const volgendeBtn = document.getElementById("gbh-next-to-step3");
                volgendeBtn.style.background = "#7d3c98";
                volgendeBtn.style.fontSize = "";
                volgendeBtn.style.padding = "10px 18px";
                volgendeBtn.style.boxShadow = "";
                const dateObj = new Date(selectedDate);
                const dayKey = map[dateObj.getDay()];
                const dayTimes = times[dayKey];
                const timesContainer = document.getElementById("gbh-times");
                const behandeltijd = parseInt(document.getElementById("gbh-total-time").textContent) || 15;
                const slotsNeeded = Math.ceil(behandeltijd / 15) + 1;
                let html = "";
                if (dayTimes && dayTimes.start && dayTimes.end) {
                    let startTs = new Date("1970-01-01T" + dayTimes.start + ":00");
                    let endTs = new Date("1970-01-01T" + dayTimes.end + ":00");
                    let allSlots = [];
                    for (let t = new Date(startTs); t <= endTs; t.setMinutes(t.getMinutes() + 15)) {
                        let h = String(t.getHours()).padStart(2, "0");
                        let m = String(t.getMinutes()).padStart(2, "0");
                        allSlots.push(h + ":" + m);
                    }
                    allSlots.forEach(function (time, index) {
                        let fitsInDay = (index + slotsNeeded) <= allSlots.length;
                        let isBlocked = false;
                        if (fitsInDay) {
                            for (let s = 0; s < slotsNeeded; s++) {
                                if (bookings.includes(selectedDate + " " + allSlots[index + s])) {
                                    isBlocked = true;
                                    break;
                                }
                            }
                        } else {
                            isBlocked = true;
                        }
                        html += "<button type=\"button\" class=\"gbh-time\" data-time=\"" + time + "\" " + (isBlocked ? "disabled" : "") + " style=\"margin:0 8px 8px 0;padding:10px 14px;border:1px solid " + (isBlocked ? "#ddd" : "#ccc") + ";border-radius:8px;background:" + (isBlocked ? "#eee" : "#fff") + ";cursor:" + (isBlocked ? "not-allowed" : "pointer") + ";\">" + time + "</button>";
                    });
                }
                timesContainer.innerHTML = html;
                tijdHeader.style.display = "table";
                tijdHeader.style.margin = "0 auto 12px auto";
                document.getElementById("gbh-datum-header").style.background = "#e8d5f5";
                document.getElementById("gbh-datum-header").style.color = "#7d3c98";
                document.getElementById("gbh-datum-header").style.fontSize = "14px";
                document.getElementById("gbh-datum-header").style.padding = "6px 12px";
                document.getElementById("gbh-datum-header").style.animation = "none";
                document.getElementById("gbh-times-header").style.animation = "gbh-knipperen-tijd 2s step-start infinite";
                document.querySelectorAll(".gbh-time").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        document.querySelectorAll(".gbh-time").forEach(function (b) {
                            b.style.background = "#fff";
                            b.style.borderColor = "#ccc";
                            b.style.color = "#000";
                        });
                        btn.style.background = "#7d3c98";
                        btn.style.borderColor = "#7d3c98";
                        btn.style.color = "#fff";
                        document.getElementById("gbh-selected-time").value = btn.dataset.time;
                        document.getElementById("gbh-chosen-time").textContent = "Gekozen tijd: " + btn.dataset.time;
                        const tijdHeader = document.getElementById("gbh-times-header");
                        tijdHeader.style.background = "#e8d5f5";
                        tijdHeader.style.color = "#7d3c98";
                        tijdHeader.style.fontSize = "14px";
                        tijdHeader.style.padding = "6px 12px";
                        document.getElementById("gbh-stap2-fout").style.display = "none";
                        const volgendeBtn = document.getElementById("gbh-next-to-step3");
                        volgendeBtn.style.background = "#4a1a6e";
                        volgendeBtn.style.fontSize = "16px";
                        volgendeBtn.style.padding = "14px 28px";
                        volgendeBtn.style.boxShadow = "0 4px 12px rgba(125,60,152,0.4)";
                        volgendeBtn.style.animation = "gbh-knipperen 2s step-start infinite";
                        document.getElementById("gbh-step2-buttons").scrollIntoView({ behavior: "smooth", block: "center" });
                    });
                });
                renderCalendar();
            });
        });
        document.getElementById("gbh-prev-month").addEventListener("click", function () {
            month--;
            if (month < 0) { month = 11; year--; }
            renderCalendar();
        });
        document.getElementById("gbh-next-month").addEventListener("click", function () {
            month++;
            if (month > 11) { month = 0; year++; }
            renderCalendar();
        });
    }

    const backToStep1Button = document.getElementById("gbh-back-to-step1");
    if (backToStep1Button) {
        backToStep1Button.addEventListener("click", function () {
            resetTimes();
            renderCalendar();
            document.getElementById("gbh-step-2").style.display = "none";
            document.querySelector(".gbh-booking").style.display = "block";
        });
    }

    renderCalendar();
});
</script>';
        echo '</div>';

        // Stap 3: gegevens invullen
        echo '<div id="gbh-step-3" style="display:none;margin-top:20px;">';
        echo '<h2>Jouw gegevens</h2>';
        echo '<form id="gbh-step3-form">';
        echo '<div id="gbh-step3-summary" style="margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:10px;max-width:400px;"></div>';
        echo '<div id="gbh-welkom" class="gbh-welkom"></div>';

        // EMAIL eerst
        echo '<label style="display:block;margin-bottom:10px;">E-mail <span style="color:#c62828;">*</span><br>';
        echo '<input type="email" id="gbh-email" required style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;">';
        echo '<span id="gbh-email-status" style="display:inline-block;margin-left:8px;font-size:13px;color:#999;"></span>';
        echo '</label>';

        // NAAM tweede
        echo '<label style="display:block;margin-bottom:10px;">Naam <span style="color:#c62828;">*</span><br><input type="text" id="gbh-naam" required style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';

        // TELEFOON derde
        echo '<label style="display:block;margin-bottom:10px;">Telefoon <span style="color:#c62828;">*</span><br><input type="tel" id="gbh-telefoon" required pattern="[\d\s\-]{10,}" title="Geef een volledig telefoonnummer van minimaal 10 cijfers" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';

        echo '<button type="submit" id="gbh-bevestig" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;margin-top:10px;">Afspraak bevestigen</button>';
        echo '<button type="button" id="gbh-back-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-top:10px;margin-left:10px;">← Terug</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const checkboxes = document.querySelectorAll(".gbh-treatment");
    const totalTime = document.getElementById("gbh-total-time");
    const totalPrice = document.getElementById("gbh-total-price");
    const nextButton = document.getElementById("gbh-next-step");
    const step1 = document.querySelector(".gbh-booking");
    const step2 = document.getElementById("gbh-step-2");
    const step3 = document.getElementById("gbh-step-3");
    const ajaxUrl = "' . $ajax_url . '";
    let emailTimer = null;
    function formatDatum(datum) {
        const parts = datum.split("-");
        return parts[2] + "-" + parts[1] + "-" + parts[0];
    }

    function updateTotals() {
        let time = 0;
        let price = 0;
        checkboxes.forEach(function (checkbox) {
            if (checkbox.checked) {
                time += parseInt(checkbox.dataset.time) || 0;
                price += parseFloat(checkbox.dataset.price) || 0;
            }
        });
        totalTime.textContent = time;
        totalPrice.textContent = price.toFixed(2).replace(".", ",");
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener("change", updateTotals);
    });

    if (nextButton) {
        nextButton.addEventListener("click", function () {
            let hasSelection = false;
            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) hasSelection = true;
            });
            if (!hasSelection) {
                const summaryBox = document.getElementById("gbh-summary");
                if (summaryBox && !document.getElementById("gbh-error")) {
                    summaryBox.insertAdjacentHTML("beforeend", "<div id=\"gbh-error\" style=\"margin-top:12px;color:#c62828;font-weight:600;\">Kies eerst minimaal één behandeling</div>");
                }
                return;
            }
            const oldError = document.getElementById("gbh-error");
            if (oldError) oldError.remove();
            step1.style.display = "none";
            step2.style.display = "block";
            window.scrollTo({ top: 0, behavior: "smooth" });
        });
    }

    const nextToStep3Button = document.getElementById("gbh-next-to-step3");
    if (nextToStep3Button) {
        nextToStep3Button.addEventListener("click", function () {
            const date = document.getElementById("gbh-selected-date").value;
            const time = document.getElementById("gbh-selected-time").value;
            if (!date || !time) {
                const fout = document.getElementById("gbh-stap2-fout");
                if (!date) {
                    fout.textContent = "Kies eerst een datum.";
                } else {
                    fout.textContent = "Kies eerst een tijdstip.";
                }
                fout.style.display = "block";
                return;
            }
            document.getElementById("gbh-stap2-fout").style.display = "none";
            const summary = document.getElementById("gbh-step3-summary");
           summary.innerHTML = "Datum: <strong>" + formatDatum(date) + "</strong><br>Tijd: <strong>" + time + "</strong><br>Behandeltijd: <strong>" + totalTime.textContent + " min</strong><br>Prijs: <strong>€" + totalPrice.textContent + "</strong>";
            step2.style.display = "none";
            step3.style.display = "block";
            document.getElementById("gbh-email").focus();
        });
    }

    // Klantherkenning via email
    const emailInput = document.getElementById("gbh-email");
    if (emailInput) {
        emailInput.addEventListener("input", function () {
            clearTimeout(emailTimer);
            const email = emailInput.value.trim();
            const status = document.getElementById("gbh-email-status");
            const welkom = document.getElementById("gbh-welkom");
            if (!email || !email.includes("@")) {
                status.textContent = "";
                welkom.style.display = "none";
                return;
            }
            status.textContent = "Zoeken...";
            emailTimer = setTimeout(function () {
                const data = new FormData();
                data.append("action", "gbh_zoek_klant");
                data.append("email", email);
                fetch(ajaxUrl, { method: "POST", body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data.gevonden) {
                        document.getElementById("gbh-naam").value = res.data.naam;
                        document.getElementById("gbh-telefoon").value = res.data.telefoon;
                        status.style.color = "#2e7d32";
                        status.textContent = "✓ Bekend";
                        welkom.style.display = "block";
                        welkom.textContent = "Welkom terug, " + res.data.naam + "! Je gegevens zijn ingevuld.";
                    } else {
                        document.getElementById("gbh-naam").value = "";
                        document.getElementById("gbh-telefoon").value = "";
                        status.style.color = "#999";
                        status.textContent = "Nieuw";
                        welkom.style.display = "none";
                    }
                });
            }, 600);
        });
    }

    const backStep3Button = document.getElementById("gbh-back-step3");
    if (backStep3Button) {
        backStep3Button.addEventListener("click", function () {
            step3.style.display = "none";
            step2.style.display = "block";
        });
    }

    const bevestigForm = document.getElementById("gbh-step3-form");
    if (bevestigForm) {
        bevestigForm.addEventListener("submit", function (e) {
            e.preventDefault();
            const naam = document.getElementById("gbh-naam").value.trim();
            const email = document.getElementById("gbh-email").value.trim();
            const telefoon = document.getElementById("gbh-telefoon").value.trim();
            const datum = document.getElementById("gbh-selected-date").value;
            const tijd = document.getElementById("gbh-selected-time").value;
            const behandeltijd = totalTime.textContent;
            const prijs = totalPrice.textContent.replace(",", ".");
            const behandelingen = [];
            document.querySelectorAll(".gbh-treatment").forEach(function (cb) {
                if (cb.checked) behandelingen.push(cb.closest("label").textContent.trim());
            });
            if (!naam || !email || !datum || !tijd) {
                return;
            }
            const data = new FormData();
            data.append("action", "gbh_save_booking");
            data.append("naam", naam);
            data.append("email", email);
            data.append("telefoon", telefoon);
            data.append("datum", datum);
            data.append("tijd", tijd);
            data.append("behandelingen", behandelingen.join(", "));
            data.append("behandeltijd", behandeltijd);
            data.append("prijs", prijs);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(function (r) { return r.json(); })
            .then(function (response) {
              if (response.success) {
                    step3.innerHTML = "<div style=\"padding:20px;border:1px solid #ccc;border-radius:10px;max-width:400px;\"><h2>Afspraak bevestigd!</h2><p>Bedankt " + naam + ", je afspraak op " + formatDatum(datum) + " om " + tijd + " is vastgelegd.</p></div>";
                } else {
                    alert("Er ging iets mis: " + response.data);
                }
            });
        });
    }

    updateTotals();
});
</script>';

        return ob_get_clean();
    }

    public function admin_menu() {
        add_menu_page(
            'GoodByeHair Booking',
            'GBH Booking',
            'manage_options',
            'gbh-booking',
            [$this, 'bookings_page'],
            'dashicons-calendar-alt',
            25
        );
        add_submenu_page(
            'gbh-booking',
            'Afspraken',
            'Afspraken',
            'manage_options',
            'gbh-booking',
            [$this, 'bookings_page']
        );
        add_submenu_page(
            'gbh-booking',
            'Klanten',
            'Klanten',
            'manage_options',
            'gbh-klanten',
            [$this, 'klanten_page']
        );
        add_submenu_page(
            'gbh-booking',
            'Instellingen',
            'Instellingen',
            'manage_options',
            'gbh-settings',
            [$this, 'settings_page']
        );
    }

    public function klanten_page() {
        global $wpdb;
        $klanten = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_klanten ORDER BY naam ASC");
        ?>
        <div class="wrap">
            <h1>Klanten</h1>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Telefoon</th>
                        <th>Aangemaakt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($klanten) : ?>
                        <?php foreach ($klanten as $k) : ?>
                            <tr>
                                <td><?php echo esc_html($k->naam); ?></td>
                                <td><?php echo esc_html($k->email); ?></td>
                                <td><?php echo esc_html($k->telefoon); ?></td>
                                <td><?php echo esc_html($k->aangemaakt); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="4">Geen klanten gevonden.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function bookings_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'gbh_bookings';
        $bookings = $wpdb->get_results("SELECT * FROM $table ORDER BY datum ASC, tijd ASC");
        $annuleer_url = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1>Afspraken</h1>
            <?php if (isset($_GET['annuleerd']) && $_GET['annuleerd'] == '1') : ?>
                <div class="notice notice-success"><p>Afspraak is geannuleerd.</p></div>
            <?php endif; ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Telefoon</th>
                        <th>Datum</th>
                        <th>Tijd</th>
                        <th>Behandelingen</th>
                        <th>Duur</th>
                        <th>Prijs</th>
                        <th>Actie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings) : ?>
                        <?php foreach ($bookings as $b) : ?>
                            <tr>
                                <td><?php echo esc_html($b->naam); ?></td>
                                <td><?php echo esc_html($b->email); ?></td>
                                <td><?php echo esc_html($b->telefoon); ?></td>
                                <td><?php echo esc_html($b->datum); ?></td>
                                <td><?php echo esc_html($b->tijd); ?></td>
                                <td><?php echo esc_html($b->behandelingen); ?></td>
                                <td><?php echo esc_html($b->behandeltijd); ?> min</td>
                                <td>€<?php echo esc_html($b->prijs); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url($annuleer_url); ?>" onsubmit="return confirm('Weet je zeker dat je deze afspraak wilt annuleren?');">
                                        <input type="hidden" name="action" value="gbh_annuleer">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($b->id); ?>">
                                        <input type="hidden" name="email" value="<?php echo esc_attr($b->email); ?>">
                                        <input type="hidden" name="naam" value="<?php echo esc_attr($b->naam); ?>">
                                        <input type="hidden" name="datum" value="<?php echo esc_attr($b->datum); ?>">
                                        <input type="hidden" name="tijd" value="<?php echo esc_attr($b->tijd); ?>">
                                        <?php wp_nonce_field('gbh_annuleer_nonce'); ?>
                                        <button type="submit" style="padding:6px 12px;border:0;border-radius:6px;background:#c62828;color:#fff;cursor:pointer;">Annuleer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="9">Geen afspraken gevonden.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function annuleer_boeking() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_annuleer_nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'gbh_bookings';
        $id    = intval($_POST['id'] ?? 0);
        $email = sanitize_email($_POST['email'] ?? '');
        $naam  = sanitize_text_field($_POST['naam'] ?? '');
        $datum = sanitize_text_field($_POST['datum'] ?? '');
        $tijd  = sanitize_text_field($_POST['tijd'] ?? '');

        $wpdb->delete($table, ['id' => $id]);

        if ($email) {
            $onderwerp = 'Afspraak geannuleerd - GoodByeHair';
            $bericht  = "Beste " . $naam . ",\n\n";
            $bericht .= "Je afspraak op " . $datum . " om " . $tijd . " is helaas geannuleerd.\n\n";
            $bericht .= "Neem contact met ons op om een nieuwe afspraak te maken.\n\n";
            $bericht .= "GoodByeHair";
            wp_mail($email, $onderwerp, $bericht);
        }

        wp_redirect(admin_url('admin.php?page=gbh-booking&annuleerd=1'));
        exit;
    }

    public function save_booking() {
        global $wpdb;
        $table   = $wpdb->prefix . 'gbh_bookings';
        $klanten = $wpdb->prefix . 'gbh_klanten';

        $naam          = sanitize_text_field($_POST['naam'] ?? '');
        $email         = sanitize_email($_POST['email'] ?? '');
        $telefoon      = sanitize_text_field($_POST['telefoon'] ?? '');
        $datum         = sanitize_text_field($_POST['datum'] ?? '');
        $tijd          = sanitize_text_field($_POST['tijd'] ?? '');
        $behandelingen = sanitize_text_field($_POST['behandelingen'] ?? '');
        $behandeltijd  = intval($_POST['behandeltijd'] ?? 0);
        $prijs         = floatval($_POST['prijs'] ?? 0);

        if (!$naam || !$email || !$datum || !$tijd) {
            wp_send_json_error('Vul alle verplichte velden in.');
        }

        // Klant opslaan of bijwerken
        $klant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $klanten WHERE email = %s", $email
        ));
        if ($klant) {
            $klant_id = $klant->id;
            if ($klant->naam !== $naam || $klant->telefoon !== $telefoon) {
                $wpdb->update($klanten, ['naam' => $naam, 'telefoon' => $telefoon], ['id' => $klant_id]);
            }
        } else {
            $wpdb->insert($klanten, ['naam' => $naam, 'email' => $email, 'telefoon' => $telefoon]);
            $klant_id = $wpdb->insert_id;
        }

        // Afspraak opslaan
        $wpdb->insert($table, [
            'klant_id'      => $klant_id,
            'naam'          => $naam,
            'email'         => $email,
            'telefoon'      => $telefoon,
            'datum'         => $datum,
            'tijd'          => $tijd,
            'behandelingen' => $behandelingen,
            'behandeltijd'  => $behandeltijd,
            'prijs'         => $prijs,
        ]);

        // E-mail naar klant
        $onderwerp_klant = 'Bevestiging afspraak GoodByeHair';
        $bericht_klant  = "Beste " . $naam . ",\n\n";
        $bericht_klant .= "Je afspraak is bevestigd!\n\n";
        $bericht_klant .= "Datum: " . date('d-m-Y', strtotime($datum)) . "\n";
        $bericht_klant .= "Tijd: " . $tijd . "\n";
        $bericht_klant .= "Behandelingen: " . $behandelingen . "\n";
        $bericht_klant .= "Behandeltijd: " . $behandeltijd . " minuten\n";
        $bericht_klant .= "Prijs: €" . number_format($prijs, 2, ',', '.') . "\n\n";
        $bericht_klant .= "Tot dan!\nGoodByeHair";
        wp_mail($email, $onderwerp_klant, $bericht_klant);

        // E-mail naar salon
        $salon_email = get_option('gbh_salon_email', '');
        if ($salon_email) {
            $onderwerp_salon = 'Nieuwe afspraak: ' . $naam;
            $bericht_salon  = "Er is een nieuwe afspraak gemaakt!\n\n";
            $bericht_salon .= "Naam: " . $naam . "\n";
            $bericht_salon .= "Email: " . $email . "\n";
            $bericht_salon .= "Telefoon: " . $telefoon . "\n";
            $bericht_salon .= "Datum: " . date('d-m-Y', strtotime($datum)) . "\n";
            $bericht_salon .= "Tijd: " . $tijd . "\n";
            $bericht_salon .= "Behandelingen: " . $behandelingen . "\n";
            $bericht_salon .= "Behandeltijd: " . $behandeltijd . " minuten\n";
            $bericht_salon .= "Prijs: €" . number_format($prijs, 2, ',', '.') . "\n";
            wp_mail($salon_email, $onderwerp_salon, $bericht_salon);
        }

        // Herinnering inplannen
        $afspraak_timestamp    = strtotime($datum . ' ' . $tijd);
        $herinnering_timestamp = $afspraak_timestamp - (24 * 60 * 60);
        if ($herinnering_timestamp > time()) {
            wp_schedule_single_event($herinnering_timestamp, 'gbh_stuur_herinnering', [$wpdb->insert_id, $email, $naam, $datum, $tijd, $behandelingen]);
        }

        wp_send_json_success('Afspraak opgeslagen.');
    }

    public function stuur_herinnering($booking_id, $email, $naam, $datum, $tijd, $behandelingen) {
        $onderwerp = 'Herinnering afspraak GoodByeHair';
        $bericht  = "Beste " . $naam . ",\n\n";
        $bericht .= "Dit is een herinnering voor je afspraak van morgen!\n\n";
        $bericht .= "Datum: " . date('d-m-Y', strtotime($datum)) . "\n";
        $bericht .= "Tijd: " . $tijd . "\n";
        $bericht .= "Behandelingen: " . $behandelingen . "\n\n";
        $bericht .= "Tot morgen!\nGoodByeHair";
        wp_mail($email, $onderwerp, $bericht);
    }

public function register_settings() {
        register_setting('gbh_settings_group', 'gbh_days');
        register_setting('gbh_settings_group', 'gbh_times');
        register_setting('gbh_settings_group', 'gbh_salon_email');
        register_setting('gbh_settings_group', 'gbh_medewerker_user');
       add_action('admin_post_gbh_sla_wachtwoord_op', [$this, 'sla_wachtwoord_op']);
    }

public function sla_wachtwoord_op() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_wachtwoord_nonce');
        $nieuw = $_POST['gbh_medewerker_pass_nieuw'] ?? '';
        $user  = sanitize_text_field($_POST['gbh_medewerker_user'] ?? '');
        if ($user) update_option('gbh_medewerker_user', $user);
        if (!empty($nieuw)) {
            update_option('gbh_medewerker_pass', password_hash($nieuw, PASSWORD_DEFAULT));
            delete_option('gbh_medewerker_token');
        }
        wp_redirect(admin_url('admin.php?page=gbh-settings&ww_opgeslagen=1'));
        exit;
    }
    
    public function settings_page() {
        $times = get_option('gbh_times', []);
        $days = get_option('gbh_days', []);
        $salon_email = get_option('gbh_salon_email', '');
        $all_days = ['ma','di','wo','do','vr','za','zo'];
        ?>
        <div class="wrap">
            <h1>Booking instellingen</h1>

            <h3>Medewerker account</h3>
            <?php if (isset($_GET['ww_opgeslagen'])) : ?>
                <div class="notice notice-success"><p>Wachtwoord opgeslagen.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gbh_sla_wachtwoord_op">
                <?php wp_nonce_field('gbh_wachtwoord_nonce'); ?>
                <label>Gebruikersnaam medewerker:<br>
                    <input type="text" name="gbh_medewerker_user" value="<?php echo esc_attr(get_option('gbh_medewerker_user', '')); ?>" style="width:300px;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;">
                </label><br><br>
                <label>Nieuw wachtwoord (laat leeg om te behouden):<br>
                    <input type="password" name="gbh_medewerker_pass_nieuw" value="" style="width:300px;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;">
                </label><br><br>
                <button type="submit" style="padding:8px 16px;border:0;border-radius:6px;background:#7d3c98;color:#fff;cursor:pointer;">Opslaan</button>
            </form>
            <br>

            <form method="post" action="options.php">
                <?php settings_fields('gbh_settings_group'); ?>

                <h3>E-mail salon</h3>
                <label>E-mailadres voor nieuwe boekingen:<br>
                    <input type="email" name="gbh_salon_email" value="<?php echo esc_attr($salon_email); ?>" style="width:300px;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;">
                </label>
                <br><br>

                <h3>Werkdagen</h3>
                <?php foreach ($all_days as $day) : ?>
                    <label>
                        <input type="checkbox" name="gbh_days[]" value="<?php echo $day; ?>" <?php checked(in_array($day, $days)); ?>>
                        <?php echo strtoupper($day); ?>
                    </label><br>
                <?php endforeach; ?>

                <h3>Tijden per dag</h3>
                <?php foreach ($all_days as $day) :
                    $start = $times[$day]['start'] ?? '';
                    $end = $times[$day]['end'] ?? '';
                    ?>
                    <label><?php echo strtoupper($day); ?> start:
                        <input type="time" name="gbh_times[<?php echo $day; ?>][start]" value="<?php echo esc_attr($start); ?>">
                    </label>
                    <label>einde:
                        <input type="time" name="gbh_times[<?php echo $day; ?>][end]" value="<?php echo esc_attr($end); ?>">
                    </label>
                    <br><br>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new GBH_Booking();
