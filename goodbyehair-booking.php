<?php
/**<?php
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

   // Blokkades tabel
    $blokkades = $wpdb->prefix . 'gbh_blokkades';
    $sql3 = "CREATE TABLE IF NOT EXISTS $blokkades (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        datum date NOT NULL,
        tijd_van time DEFAULT NULL,
        tijd_tot time DEFAULT NULL,
        hele_dag tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

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
        add_action('wp_ajax_gbh_blokkade_opslaan', [$this, 'blokkade_opslaan']);
        add_action('wp_ajax_nopriv_gbh_blokkade_opslaan', [$this, 'blokkade_opslaan']);
        add_action('wp_ajax_gbh_blokkade_verwijderen', [$this, 'blokkade_verwijderen']);
        add_action('wp_ajax_nopriv_gbh_blokkade_verwijderen', [$this, 'blokkade_verwijderen']);
        add_action('wp_ajax_gbh_get_blokkades', [$this, 'get_blokkades']);
        add_action('wp_ajax_nopriv_gbh_get_blokkades', [$this, 'get_blokkades']);
        add_action('wp_ajax_gbh_get_week_data', [$this, 'get_week_data']);
        add_action('wp_ajax_nopriv_gbh_get_week_data', [$this, 'get_week_data']);
        add_action('wp_ajax_gbh_wijzig_afspraak', [$this, 'wijzig_afspraak']);
        add_action('wp_ajax_nopriv_gbh_wijzig_afspraak', [$this, 'wijzig_afspraak']);
        add_action('wp_ajax_gbh_verwijder_afspraak', [$this, 'verwijder_afspraak']);
        add_action('wp_ajax_nopriv_gbh_verwijder_afspraak', [$this, 'verwijder_afspraak']);
        add_filter('wp_mail_from', function($email) { return 'info@goodbyehair.nl'; });
        add_filter('wp_mail_from_name', function($name) { return 'Goodbyehair'; });
    }

   // -------------------------
    // BLOKKADE OPSLAAN
    // -------------------------

     // -------------------------
    // WEEK DATA OPHALEN
    // -------------------------
    public function get_week_data() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $week_start = sanitize_text_field($_POST['week_start'] ?? '');
        if (!$week_start) wp_send_json_error('Geen datum.');

        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));

        $afspraken = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_bookings WHERE datum >= %s AND datum <= %s ORDER BY datum ASC, tijd ASC",
            $week_start, $week_end
        ));

        $blokkades = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_blokkades WHERE datum >= %s AND datum <= %s ORDER BY datum ASC, tijd_van ASC",
            $week_start, $week_end
        ));

        wp_send_json_success([
            'afspraken' => $afspraken,
            'blokkades' => $blokkades,
        ]);
    }

    // -------------------------
    // AFSPRAAK WIJZIGEN
    // -------------------------
    public function wijzig_afspraak() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id            = intval($_POST['id'] ?? 0);
        $naam          = sanitize_text_field($_POST['naam'] ?? '');
        $email         = sanitize_email($_POST['email'] ?? '');
        $telefoon      = sanitize_text_field($_POST['telefoon'] ?? '');
        $datum         = sanitize_text_field($_POST['datum'] ?? '');
        $tijd          = sanitize_text_field($_POST['tijd'] ?? '');
        $behandelingen = sanitize_text_field($_POST['behandelingen'] ?? '');
        $behandeltijd  = intval($_POST['behandeltijd'] ?? 0);
        $prijs         = floatval($_POST['prijs'] ?? 0);

        if (!$id || !$naam || !$email || !$datum || !$tijd) {
            wp_send_json_error('Vul alle velden in.');
        }

        // Controleer of het nieuwe tijdslot bezet is (exclusief de huidige afspraak zelf)
        $slots_nodig  = ceil($behandeltijd / 15) + 1;
        $nieuwe_start = strtotime('1970-01-01 ' . $tijd);
        $nieuwe_eind  = $nieuwe_start + ($slots_nodig * 15 * 60);

        $bestaande = $wpdb->get_results($wpdb->prepare(
            "SELECT tijd, behandeltijd FROM {$wpdb->prefix}gbh_bookings WHERE datum = %s AND id != %d",
            $datum, $id
        ));
        $blokkades = $wpdb->get_results($wpdb->prepare(
            "SELECT hele_dag, tijd_van, tijd_tot FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s",
            $datum
        ));

        $bezet = false;
        foreach ($bestaande as $b) {
            $b_start = strtotime('1970-01-01 ' . substr($b->tijd, 0, 5));
            $b_eind  = $b_start + ((ceil($b->behandeltijd / 15) + 1) * 15 * 60);
            if ($nieuwe_start < $b_eind && $nieuwe_eind > $b_start) { $bezet = true; break; }
        }
        foreach ($blokkades as $bl) {
            if ($bl->hele_dag) { $bezet = true; break; }
            $bl_start = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bl_eind  = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            if ($nieuwe_start < $bl_eind && $nieuwe_eind > $bl_start) { $bezet = true; break; }
        }

        if ($bezet) {
            wp_send_json_error('Dit tijdslot is al bezet. Kies een ander tijdstip.');
        }

        $wpdb->update(
            $wpdb->prefix . 'gbh_bookings',
            compact('naam', 'email', 'telefoon', 'datum', 'tijd', 'behandelingen', 'behandeltijd', 'prijs'),
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f'],
            ['%d']
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht .= "Beste " . $naam . ",<br><br>";
        $bericht .= "Je afspraak is gewijzigd.<br><br>";
        $bericht .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht .= "Tijd: " . $tijd . "<br>";
        $bericht .= "Behandelingen: " . $behandelingen . "<br>";
        $bericht .= "Behandeltijd: " . $behandeltijd . " minuten<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, 'Wijziging afspraak GoodByeHair', $bericht, $headers);

        wp_send_json_success('Afspraak gewijzigd.');
    }

    // -------------------------
    // AFSPRAAK VERWIJDEREN
    // -------------------------
    public function verwijder_afspraak() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Geen ID.');

        $boek = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_bookings WHERE id = %d", $id
        ));
        if (!$boek) wp_send_json_error('Afspraak niet gevonden.');

        $wpdb->delete($wpdb->prefix . 'gbh_bookings', ['id' => $id], ['%d']);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = "Beste " . $boek->naam . ",<br><br>";
        $bericht .= "Je afspraak op " . date('d-m-Y', strtotime($boek->datum)) . " om " . substr($boek->tijd, 0, 5) . " is geannuleerd.<br><br>";
        $bericht .= "Neem contact met ons op om een nieuwe afspraak te maken.<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair";
        wp_mail($boek->email, 'Afspraak geannuleerd - GoodByeHair', $bericht, $headers);

        wp_send_json_success('Afspraak verwijderd.');
    }   
    public function blokkade_opslaan() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $datum     = sanitize_text_field($_POST['datum'] ?? '');
        $hele_dag  = intval($_POST['hele_dag'] ?? 0);
        $tijd_van  = sanitize_text_field($_POST['tijd_van'] ?? '');
        $tijd_tot  = sanitize_text_field($_POST['tijd_tot'] ?? '');
        if (!$datum) wp_send_json_error('Kies een datum.');
        if (!$hele_dag && (!$tijd_van || !$tijd_tot)) wp_send_json_error('Vul een tijd van en tot in.');
        $bestaande = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s", $datum
        ));
        foreach ($bestaande as $bl) {
            if ($bl->hele_dag) {
                wp_send_json_error('Deze dag is al volledig geblokkeerd.');
            }
            if ($hele_dag) {
                wp_send_json_error('Er bestaan al tijdblokken op deze dag. Verwijder die eerst.');
            }
            $bestaand_van = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bestaand_tot = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            $nieuw_van    = strtotime('1970-01-01 ' . $tijd_van);
            $nieuw_tot    = strtotime('1970-01-01 ' . $tijd_tot);
            if ($nieuw_van < $bestaand_tot && $nieuw_tot > $bestaand_van) {
                wp_send_json_error('Dit tijdblok overlapt met een bestaande blokkade.');
            }
        }
        $wpdb->insert($wpdb->prefix . 'gbh_blokkades', [
            'datum'    => $datum,
            'hele_dag' => $hele_dag,
            'tijd_van' => $hele_dag ? null : $tijd_van,
            'tijd_tot' => $hele_dag ? null : $tijd_tot,
        ]);
        wp_send_json_success(['bericht' => 'Blokkade opgeslagen.', 'id' => $wpdb->insert_id]);
    }

    // -------------------------
    // BLOKKADE VERWIJDEREN
    // -------------------------
    public function blokkade_verwijderen() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gbh_blokkades', ['id' => $id]);
        wp_send_json_success('Blokkade verwijderd.');
    }
// -------------------------
    // BLOKKADES OPHALEN
    // -------------------------
    public function get_blokkades() {
        global $wpdb;
        $blokkades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades", ARRAY_A);
        $geblokkeerde_dagen = [];
        $geblokkeerde_slots = [];
        foreach ($blokkades as $bl) {
            if ($bl['hele_dag']) {
                $geblokkeerde_dagen[] = $bl['datum'];
            } else {
                $van_ts = strtotime('1970-01-01 ' . substr($bl['tijd_van'], 0, 5));
                $tot_ts = strtotime('1970-01-01 ' . substr($bl['tijd_tot'], 0, 5));
                for ($t = $van_ts; $t < $tot_ts; $t += 15 * 60) {
                    $geblokkeerde_slots[] = $bl['datum'] . ' ' . date('H:i', $t);
                }
            }
        }
        wp_send_json_success([
            'geblokkeerde_dagen' => $geblokkeerde_dagen,
            'geblokkeerde_slots' => $geblokkeerde_slots,
        ]);
    }
     // -------------------------
    // LOGIN / LOGOUT (eigen systeem)
    // -------------------------
        private function gbh_is_ingelogd() {

      if (empty($_COOKIE['gbh_medewerker'])) return false;

        $cookie = sanitize_text_field(wp_unslash($_COOKIE['gbh_medewerker']));
        if (strpos($cookie, '|') === false) return false;

        list($session_id, $token) = explode('|', $cookie, 2);
        if (!$session_id || !$token) return false;

        $sessions = get_option('gbh_medewerker_tokens', []);
        if (!is_array($sessions) || empty($sessions[$session_id])) return false;

        $session = $sessions[$session_id];

        if (empty($session['token']) || empty($session['expires'])) return false;

        if (time() > intval($session['expires'])) {
            unset($sessions[$session_id]);
            update_option('gbh_medewerker_tokens', $sessions);
            return false;
        }

        return hash_equals($session['token'], hash('sha256', $token));
    }

        public function handle_login() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
        wp_send_json_error('Ongeldige aanvraag.');
    }

    $username = strtolower(sanitize_text_field($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $opgeslagen_user = strtolower(get_option('gbh_medewerker_user', ''));
    $opgeslagen_pass = get_option('gbh_medewerker_pass', '');

    $pogingen_key = 'gbh_login_pogingen_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $pogingen = (int) get_transient($pogingen_key);
    if ($pogingen >= 5) {
        wp_send_json_error('Te veel inlogpogingen. Probeer het over 15 minuten opnieuw.');
    }
    if ($username !== $opgeslagen_user || !password_verify($password, $opgeslagen_pass)) {
        set_transient($pogingen_key, $pogingen + 1, 15 * 60);
        wp_send_json_error('Gebruikersnaam of wachtwoord onjuist.');
    }
    delete_transient($pogingen_key);

    $session_id = bin2hex(random_bytes(16));
    $token      = bin2hex(random_bytes(32));
    $expires    = time() + (8 * 60 * 60);

    $sessions = get_option('gbh_medewerker_tokens', []);
    if (!is_array($sessions)) $sessions = [];

    foreach ($sessions as $key => $session) {
        if (empty($session['expires']) || time() > intval($session['expires'])) {
            unset($sessions[$key]);
        }
    }

    $sessions[$session_id] = [
        'token'   => hash('sha256', $token),
        'expires' => $expires,
    ];
    update_option('gbh_medewerker_tokens', $sessions);

    $cookie_waarde = $session_id . '|' . $token;

    setcookie('gbh_medewerker', $cookie_waarde, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    wp_send_json_success('Ingelogd');
}

public function handle_logout() {
    // Nonce check toegevoegd (ontbrak eerder)
    if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
        wp_send_json_error('Ongeldige aanvraag.');
    }

    $sessions = get_option('gbh_medewerker_tokens', []);
    if (!is_array($sessions)) $sessions = [];

    // Huidige sessie verwijderen uit opgeslagen sessies
    if (!empty($_COOKIE['gbh_medewerker'])) {
        $cookie = sanitize_text_field(wp_unslash($_COOKIE['gbh_medewerker']));
        if (strpos($cookie, '|') !== false) {
            list($session_id) = explode('|', $cookie, 2);
            if (!empty($sessions[$session_id])) {
                unset($sessions[$session_id]);
                update_option('gbh_medewerker_tokens', $sessions);
            }
        }
    }

    // Cookie verwijderen met dezelfde opties als bij het instellen
    $cookie_opties = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',        // moet exact hetzelfde zijn als bij instellen
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if (!headers_sent()) {
        setcookie('gbh_medewerker', '', $cookie_opties);
    }

    wp_send_json_success('Uitgelogd');
}
    // -------------------------
    // KLANT OPSLAAN (medewerker)
    // -------------------------
   public function klant_opslaan() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
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
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $aantal = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gbh_bookings WHERE klant_id = %d", $id
        ));
        if ($aantal > 0) {
            wp_send_json_error('Deze klant heeft nog ' . $aantal . ' afspraak/afspraken. Verwijder eerst de afspraken.');
        }
        $wpdb->delete($wpdb->prefix . 'gbh_klanten', ['id' => $id]);
        wp_send_json_success('Verwijderd.');
    }

    // -------------------------
    // FRONTEND MEDEWERKER PANEL
    // -------------------------
    public function render_medewerker() {
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('gbh_ajax_nonce');
        ob_start();
        echo '<div id="gbh-medewerker-wrap">';

        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            // Loginformulier
            echo '<div id="gbh-login-form" style="max-width:360px;margin:0 auto;padding:24px;border:2px solid #7d3c98;border-radius:12px;background:#faf5ff;">';
            echo '<h2 style="color:#7d3c98;margin-top:0;">Medewerker login</h2>';
            echo '<div id="gbh-login-error" style="color:#c62828;margin-bottom:10px;display:none;"></div>';
            echo '<label style="display:block;margin-bottom:10px;">Gebruikersnaam<br><input type="text" id="gbh-login-user" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"></label>';
            echo '<label style="display:block;margin-bottom:16px;">Wachtwoord<br><div style="position:relative;"><input type="password" id="gbh-login-pass" style="width:100%;padding:10px;padding-right:44px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"><button type="button" onclick="const p=document.getElementById(\'gbh-login-pass\');p.type=p.type===\'password\'?\'text\':\'password\';" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#999;font-size:13px;">Toon</button></div></label>';
            echo '<button type="button" id="gbh-login-btn" style="width:100%;padding:12px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;font-size:15px;font-weight:600;">Inloggen</button>';
            echo '</div>';
           echo '<script>
function gbhKoppelLogin() {
    var btn = document.getElementById("gbh-login-btn");
    if (!btn) { setTimeout(gbhKoppelLogin, 100); return; }
    btn.addEventListener("click", function() {
        const user = document.getElementById("gbh-login-user").value;
        const pass = document.getElementById("gbh-login-pass").value;
        const error = document.getElementById("gbh-login-error");
        const data = new FormData();
        data.append("action", "gbh_login");
        data.append("gbh_nonce", "' . $nonce . '");
        data.append("username", user);
        data.append("password", pass);
       fetch("' . $ajax_url . '", { method: "POST", body: data, credentials: "same-origin" })
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
}
gbhKoppelLogin();
</script>';
        } else {
            // Dashboard
            global $wpdb;
            $klanten = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_klanten ORDER BY naam ASC");
            $medewerker_naam = get_option('gbh_medewerker_user', 'medewerker');

            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">';
            echo '<h2 style="color:#7d3c98;margin:0;">Dashboard</h2>';
            echo '<div style="display:flex;gap:10px;align-items:center;">';
            echo '<span style="font-size:14px;color:#666;">Ingelogd als: <strong>' . esc_html($medewerker_naam) . '</strong></span>';
            echo '<button type="button" id="gbh-logout-btn" style="padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Uitloggen</button>';
            echo '</div>';
            echo '</div>';

            echo '<div id="gbh-dashboard" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:30px;">';
            echo '<button type="button" id="gbh-dash-blokkeren" style="padding:20px 30px;border:0;border-radius:12px;background:#c62828;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">📅 Tijd blokkeren</button>';
            echo '<button type="button" id="gbh-dash-klanten" style="padding:20px 30px;border:0;border-radius:12px;background:#7d3c98;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">👥 Klantenbestand</button>';
            echo '<button type="button" id="gbh-dash-agenda" style="padding:20px 30px;border:0;border-radius:12px;background:#1565c0;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">🗓 Agendaoverzicht</button>';
            echo '</div>';

            echo '<div id="gbh-sectie-blokkeren" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';

            // Blokkades paneel direct na header
            $blokkades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades ORDER BY datum ASC, tijd_van ASC");
            echo '<div id="gbh-blok-paneel" style="margin-bottom:24px;padding:16px;border:2px solid #c62828;border-radius:12px;background:#fff8f8;">';
            echo '<h3 style="color:#c62828;margin-top:0;">Tijd blokkeren</h3>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">';
            echo '<div><label style="font-size:13px;">Datum van<br><input type="date" id="gbh-blok-datum" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label></div>';
            echo '<div id="gbh-blok-datum-tot-wrap" style="display:none;"><label style="font-size:13px;">Datum tot en met<br><input type="date" id="gbh-blok-datum-tot" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label></div>';
            echo '<div><label style="font-size:13px;"><input type="checkbox" id="gbh-blok-heledag" style="margin-right:6px;">Hele dag</label></div>';
            echo '<div id="gbh-blok-tijden" style="display:flex;gap:10px;">';
            echo '<label style="font-size:13px;">Van<br><input type="time" id="gbh-blok-van" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tot<br><input type="time" id="gbh-blok-tot" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
            echo '</div>';
            echo '<button type="button" id="gbh-blok-btn" style="padding:10px 18px;border:0;border-radius:8px;background:#c62828;color:#fff;cursor:pointer;font-weight:600;">Blokkeren</button>';
            echo '<button type="button" id="gbh-blok-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;color:#000;cursor:pointer;">Terug</button>';
            echo '</div>';
            echo '<div id="gbh-blok-msg" style="font-size:14px;margin-bottom:10px;"></div>';
            echo '<div id="gbh-blokkades-lijst">';
            if ($blokkades) {
                echo '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
                echo '<thead><tr style="background:#fdecea;"><th style="padding:8px;text-align:left;">Datum</th><th style="padding:8px;text-align:left;">Tijd</th><th style="padding:8px;"></th></tr></thead>';
                echo '<tbody>';
                foreach ($blokkades as $bl) {
                    $datum_nl = date('d-m-Y', strtotime($bl->datum));
                    $tijd_str = $bl->hele_dag ? 'Hele dag' : substr($bl->tijd_van, 0, 5) . ' - ' . substr($bl->tijd_tot, 0, 5);
                    echo '<tr style="border-bottom:1px solid #eee;">';
                    echo '<td style="padding:8px;">' . esc_html($datum_nl) . '</td>';
                    echo '<td style="padding:8px;">' . esc_html($tijd_str) . '</td>';
                    echo '<td style="padding:8px;text-align:right;"><button type="button" class="gbh-blok-del" data-id="' . esc_attr($bl->id) . '" style="padding:4px 12px;border:0;border-radius:6px;background:#c62828;color:#fff;cursor:pointer;font-size:13px;">Verwijderen</button></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p style="color:#999;font-size:14px;">Geen blokkades.</p>';
            }
           echo '</div>';
            echo '</div>';
            echo '</div>'; // einde gbh-sectie-blokkeren

            echo '<div id="gbh-sectie-klanten" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';

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
                    echo '<button type="button" class="gbh-afspraak-btn" data-id="' . esc_attr($k->id) . '" data-naam="' . esc_attr($k->naam) . '" data-email="' . esc_attr($k->email) . '" data-telefoon="' . esc_attr($k->telefoon) . '" style="padding:6px 14px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;">Afspraak</button>';
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
                    echo '<button type="button" class="gbh-annuleer-btn" data-id="' . esc_attr($k->id) . '" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;color:#000;cursor:pointer;">Annuleren</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<p style="color:#999;">Nog geen klanten gevonden.</p>';
            }
            echo '</div>';
            echo '</div>'; // einde gbh-sectie-klanten

        echo '<div id="gbh-afspraak-nieuw-popup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #1565c0;border-radius:14px;padding:24px;z-index:9999;min-width:340px;max-width:560px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);">';
            echo '<h3 style="color:#1565c0;margin-top:0;">Nieuwe afspraak</h3>';
            echo '<input type="hidden" id="gbh-nieuw-afspraak-klant-id">';
            echo '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">';
            echo '<label style="font-size:13px;">Naam<br><input type="text" id="gbh-nieuw-afspraak-naam" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Email<br><input type="email" id="gbh-nieuw-afspraak-email" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Telefoon<br><input type="tel" id="gbh-nieuw-afspraak-telefoon" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Datum<br><input type="date" id="gbh-nieuw-afspraak-datum" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tijd<br><input type="time" id="gbh-nieuw-afspraak-tijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '</div>';
            echo '<strong style="font-size:13px;">Behandelingen</strong>';
            echo '<div style="display:flex;flex-direction:column;gap:4px;margin:8px 0 12px 0;">';
            $behandelingen_lijst = [
                'Gezicht' => [
                    'Bovenlip' => [15, 19], 'Kin' => [15, 19], 'Kaaklijn' => [15, 35],
                    'Nek' => [15, 25], 'Hals' => [15, 25], 'Wangen' => [15, 19], 'Gehele gezicht' => [20, 75],
                ],
                'Lichaam' => [
                    'Oksels' => [20, 39], 'Onderarm' => [15, 49], 'Bovenarm' => [15, 49],
                    'Armen geheel' => [30, 89], 'Borst' => [30, 35], 'Tepels rondom' => [15, 19],
                    'Buik' => [20, 49], 'Navelstrook' => [20, 19], 'Onderrug' => [20, 49],
                    'Bovenrug' => [20, 49], 'Rug geheel' => [30, 89], 'Bikinilijn klein' => [15, 25],
                    'Bikinilijn groot' => [20, 55], 'Onderbenen' => [20, 65], 'Bovenbenen' => [20, 65],
                    'Benen geheel' => [30, 119],
                ],
            ];
            foreach ($behandelingen_lijst as $cat => $items) {
                echo '<strong style="font-size:12px;color:#666;margin-top:6px;">' . esc_html($cat) . '</strong>';
                foreach ($items as $naam => $info) {
                    echo '<label style="font-size:13px;display:flex;align-items:center;gap:8px;">';
                    echo '<input type="checkbox" class="gbh-nieuw-behandeling" data-naam="' . esc_attr($naam) . '" data-tijd="' . esc_attr($info[0]) . '" data-prijs="' . esc_attr($info[1]) . '"> ';
                    echo esc_html($naam) . ' (' . esc_html($info[0]) . ' min) — €' . esc_html($info[1]);
                    echo '</label>';
                }
            }
            echo '</div>';
            echo '<div id="gbh-nieuw-afspraak-msg" style="font-size:13px;margin-bottom:8px;"></div>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<button type="button" id="gbh-nieuw-afspraak-opslaan" style="padding:10px 18px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;font-weight:600;">Afspraak maken</button>';
            echo '<button type="button" id="gbh-nieuw-afspraak-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Annuleren</button>';
            echo '</div>';
            echo '</div>';
            echo '<div id="gbh-nieuw-afspraak-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:9998;"></div>';

           echo '<div id="gbh-sectie-agenda" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';
            echo '<h3 style="color:#1565c0;margin-top:0;">Agendaoverzicht</h3>';

            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:10px;flex-wrap:wrap;">';
            echo '<div style="display:flex;gap:8px;">';
            echo '<button type="button" id="gbh-week-prev" style="padding:8px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Vorige week</button>';
            echo '<button type="button" id="gbh-week-next" style="padding:8px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Volgende week →</button>';
            echo '</div>';
            echo '<span id="gbh-week-label" style="font-weight:600;font-size:16px;"></span>';
            echo '</div>';

            echo '<div id="gbh-week-kalender" style="overflow-x:auto;"></div>';

           echo '<div id="gbh-afspraak-popup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #1565c0;border-radius:14px;padding:24px;z-index:9999;min-width:320px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);">';
            echo '<h3 style="color:#1565c0;margin-top:0;" id="gbh-popup-titel">Afspraak bewerken</h3>';
            echo '<input type="hidden" id="gbh-popup-id">';
            echo '<div style="display:flex;flex-direction:column;gap:6px;">';
            echo '<label style="font-size:13px;">Naam<br><input type="text" id="gbh-popup-naam" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Email<br><input type="email" id="gbh-popup-email" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Telefoon<br><input type="tel" id="gbh-popup-telefoon" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Datum<br><input type="date" id="gbh-popup-datum" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tijd<br><input type="time" id="gbh-popup-tijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Behandelingen<br><input type="text" id="gbh-popup-behandelingen" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Behandeltijd (min)<br><input type="number" id="gbh-popup-behandeltijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Prijs (€)<br><input type="number" step="0.01" id="gbh-popup-prijs" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '</div>';
            echo '<div id="gbh-popup-msg" style="margin-top:10px;font-size:13px;"></div>';
            echo '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">';
            echo '<button type="button" id="gbh-popup-opslaan" style="padding:10px 18px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;font-weight:600;">Opslaan</button>';
            echo '<button type="button" id="gbh-popup-verwijderen" style="padding:10px 18px;border:0;border-radius:8px;background:#c62828;color:#fff;cursor:pointer;font-weight:600;">Verwijderen</button>';
            echo '<button type="button" id="gbh-popup-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Annuleren</button>';
            echo '</div>';
            echo '</div>';

            echo '<div id="gbh-popup-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:9998;"></div>';

            echo '<script>
(function() {
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . wp_create_nonce('gbh_ajax_nonce') . '";
    
    window.gbhLaadWeek = function() { laadWeek(); };

    function toonSectie(id) {
        document.getElementById("gbh-dashboard").style.display = "none";
        document.getElementById("gbh-sectie-blokkeren").style.display = "none";
        document.getElementById("gbh-sectie-klanten").style.display = "none";
        document.getElementById("gbh-sectie-agenda").style.display = "none";
        document.getElementById(id).style.display = "block";
    }
    const dagLabels = ["Ma","Di","Wo","Do","Vr","Za","Zo"];
    const today = new Date();
    today.setHours(0,0,0,0);

    function getMaandag(d) {
        const dag = new Date(d);
        const diff = dag.getDay() === 0 ? -6 : 1 - dag.getDay();
        dag.setDate(dag.getDate() + diff);
        dag.setHours(0,0,0,0);
        return dag;
    }

    let huidigeMaandag = getMaandag(new Date());

    function formatDatum(d) {
        return String(d.getDate()).padStart(2,"0") + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + d.getFullYear();
    }

    function isoDate(d) {
        return d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + String(d.getDate()).padStart(2,"0");
    }

    function laadWeek() {
        const weekStart = isoDate(huidigeMaandag);
        const zondag = new Date(huidigeMaandag);
        zondag.setDate(zondag.getDate() + 6);
        document.getElementById("gbh-week-label").textContent = formatDatum(huidigeMaandag) + " t/m " + formatDatum(zondag);

        const data = new FormData();
        data.append("action", "gbh_get_week_data");
        data.append("gbh_nonce", gbhNonce);
        data.append("week_start", weekStart);

        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            renderWeek(res.data.afspraken, res.data.blokkades);
        });
    }

    function renderWeek(afspraken, blokkades) {
        const wrap = document.getElementById("gbh-week-kalender");
        let html = "<div style=\"display:grid;grid-template-columns:repeat(7,1fr);gap:6px;min-width:560px;\">";

        for (let i = 0; i < 7; i++) {
            const dag = new Date(huidigeMaandag);
            dag.setDate(dag.getDate() + i);
            const dagIso = isoDate(dag);
            const isVandaag = dagIso === isoDate(today);

            html += "<div style=\"border:1px solid " + (isVandaag ? "#1565c0" : "#ddd") + ";border-radius:10px;padding:8px;background:" + (isVandaag ? "#e3f0ff" : "#fff") + ";min-height:120px;\">";
            html += "<div style=\"font-weight:600;font-size:13px;margin-bottom:6px;color:" + (isVandaag ? "#1565c0" : "#333") + ";\">" + dagLabels[i] + " " + formatDatum(dag) + "</div>";

            afspraken.forEach(function(a) {
                if (a.datum === dagIso) {
                    html += "<div class=\"gbh-agenda-afspraak\" data-id=\"" + a.id + "\" data-naam=\"" + a.naam.replace(/"/g,"&quot;") + "\" data-email=\"" + a.email.replace(/"/g,"&quot;") + "\" data-telefoon=\"" + a.telefoon.replace(/"/g,"&quot;") + "\" data-datum=\"" + a.datum + "\" data-tijd=\"" + a.tijd.substring(0,5) + "\" data-behandelingen=\"" + a.behandelingen.replace(/"/g,"&quot;") + "\" data-behandeltijd=\"" + a.behandeltijd + "\" data-prijs=\"" + a.prijs + "\" style=\"background:#7d3c98;color:#fff;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;cursor:pointer;\">";
                    html += "<strong>" + a.tijd.substring(0,5) + "</strong> " + a.naam + "<br><span style=\"font-size:11px;opacity:0.85;\">" + a.behandelingen + "</span>";
                    html += "</div>";
                }
            });

            blokkades.forEach(function(b) {
                if (b.datum === dagIso) {
                    const tijdStr = b.hele_dag == 1 ? "Hele dag" : b.tijd_van.substring(0,5) + " - " + b.tijd_tot.substring(0,5);
                    html += "<div style=\"background:#fdecea;color:#c62828;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;\">🚫 " + tijdStr + "</div>";
                }
            });

            html += "</div>";
        }

        html += "</div>";
        wrap.innerHTML = html;

        document.querySelectorAll(".gbh-agenda-afspraak").forEach(function(el) {
            el.addEventListener("click", function() {
                document.getElementById("gbh-popup-id").value = el.dataset.id;
                document.getElementById("gbh-popup-naam").value = el.dataset.naam;
                document.getElementById("gbh-popup-email").value = el.dataset.email;
                document.getElementById("gbh-popup-telefoon").value = el.dataset.telefoon;
                document.getElementById("gbh-popup-datum").value = el.dataset.datum;
                document.getElementById("gbh-popup-tijd").value = el.dataset.tijd;
                document.getElementById("gbh-popup-behandelingen").value = el.dataset.behandelingen;
                document.getElementById("gbh-popup-behandeltijd").value = el.dataset.behandeltijd;
                document.getElementById("gbh-popup-prijs").value = el.dataset.prijs;
                document.getElementById("gbh-popup-msg").textContent = "";
                document.getElementById("gbh-afspraak-popup").style.display = "block";
                document.getElementById("gbh-popup-overlay").style.display = "block";
            });
        });
    }

    document.getElementById("gbh-week-prev").addEventListener("click", function() {
        huidigeMaandag.setDate(huidigeMaandag.getDate() - 7);
        laadWeek();
    });

    document.getElementById("gbh-week-next").addEventListener("click", function() {
        huidigeMaandag.setDate(huidigeMaandag.getDate() + 7);
        laadWeek();
    });

    document.getElementById("gbh-popup-sluiten").addEventListener("click", function() {
        document.getElementById("gbh-afspraak-popup").style.display = "none";
        document.getElementById("gbh-popup-overlay").style.display = "none";
    });

    document.getElementById("gbh-popup-overlay").addEventListener("click", function() {
        document.getElementById("gbh-afspraak-popup").style.display = "none";
        document.getElementById("gbh-popup-overlay").style.display = "none";
    });

    document.getElementById("gbh-popup-opslaan").addEventListener("click", function() {
        const data = new FormData();
        data.append("action", "gbh_wijzig_afspraak");
        data.append("gbh_nonce", gbhNonce);
        data.append("id", document.getElementById("gbh-popup-id").value);
        data.append("naam", document.getElementById("gbh-popup-naam").value);
        data.append("email", document.getElementById("gbh-popup-email").value);
        data.append("telefoon", document.getElementById("gbh-popup-telefoon").value);
        data.append("datum", document.getElementById("gbh-popup-datum").value);
        data.append("tijd", document.getElementById("gbh-popup-tijd").value);
        data.append("behandelingen", document.getElementById("gbh-popup-behandelingen").value);
        data.append("behandeltijd", document.getElementById("gbh-popup-behandeltijd").value);
        data.append("prijs", document.getElementById("gbh-popup-prijs").value);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            const msg = document.getElementById("gbh-popup-msg");
            if (res.success) {
                msg.style.color = "#2e7d32";
                msg.textContent = "Opgeslagen!";
                setTimeout(function() {
                    document.getElementById("gbh-afspraak-popup").style.display = "none";
                    document.getElementById("gbh-popup-overlay").style.display = "none";
                    laadWeek();
                }, 800);
            } else {
                msg.style.color = "#c62828";
                msg.textContent = res.data;
            }
        });
    });

    document.getElementById("gbh-popup-verwijderen").addEventListener("click", function() {
        if (!confirm("Weet je zeker dat je deze afspraak wilt verwijderen?")) return;
        const data = new FormData();
        data.append("action", "gbh_verwijder_afspraak");
        data.append("gbh_nonce", gbhNonce);
        data.append("id", document.getElementById("gbh-popup-id").value);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById("gbh-afspraak-popup").style.display = "none";
                document.getElementById("gbh-popup-overlay").style.display = "none";
                laadWeek();
            }
        });
    });

  document.getElementById("gbh-dash-agenda").addEventListener("click", function() {
        toonSectie("gbh-sectie-agenda");
        window.gbhLaadWeek();
    });
})();
</script>';

            echo '</div>';

            echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . $nonce . '";

    function toonSectie(id) {
        document.getElementById("gbh-dashboard").style.display = "none";
        document.getElementById("gbh-sectie-blokkeren").style.display = "none";
        document.getElementById("gbh-sectie-klanten").style.display = "none";
        document.getElementById("gbh-sectie-agenda").style.display = "none";
        document.getElementById(id).style.display = "block";
    }

    document.getElementById("gbh-dash-blokkeren").addEventListener("click", function() { toonSectie("gbh-sectie-blokkeren"); });
    document.getElementById("gbh-dash-klanten").addEventListener("click", function() { toonSectie("gbh-sectie-klanten"); });
        document.querySelectorAll(".gbh-terug-dashboard").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.getElementById("gbh-sectie-blokkeren").style.display = "none";
            document.getElementById("gbh-sectie-klanten").style.display = "none";
            document.getElementById("gbh-sectie-agenda").style.display = "none";
            document.getElementById("gbh-dashboard").style.display = "flex";
        });
    });

       document.getElementById("gbh-blok-sluiten").addEventListener("click", function() {
        document.getElementById("gbh-blok-paneel").style.display = "none";
    });

    document.getElementById("gbh-blok-heledag").addEventListener("change", function() {
        document.getElementById("gbh-blok-tijden").style.display = this.checked ? "none" : "flex";
        document.getElementById("gbh-blok-datum-tot-wrap").style.display = this.checked ? "block" : "none";
        if (this.checked) {
            const datumVan = document.getElementById("gbh-blok-datum").value;
            if (datumVan) document.getElementById("gbh-blok-datum-tot").value = datumVan;
        }
    });

    document.getElementById("gbh-blok-btn").addEventListener("click", function() {
        const datum_van = document.getElementById("gbh-blok-datum").value;
        const hele_dag = document.getElementById("gbh-blok-heledag").checked ? 1 : 0;
        const datum_tot = hele_dag ? document.getElementById("gbh-blok-datum-tot").value : datum_van;
        const tijd_van = document.getElementById("gbh-blok-van").value;
        const tijd_tot = document.getElementById("gbh-blok-tot").value;
        const msg = document.getElementById("gbh-blok-msg");
        if (!datum_van) { msg.style.color = "#c62828"; msg.textContent = "Kies een datum."; return; }
        if (!hele_dag && (!tijd_van || !tijd_tot)) { msg.style.color = "#c62828"; msg.textContent = "Vul een tijd van en tot in."; return; }
        if (!hele_dag && tijd_van >= tijd_tot) { msg.style.color = "#c62828"; msg.textContent = "Eindtijd moet na begintijd liggen."; return; }
        if (hele_dag && datum_tot && datum_tot < datum_van) { msg.style.color = "#c62828"; msg.textContent = "Datum tot en met moet na datum van liggen."; return; }
        const eindDatum = datum_tot || datum_van;
        const taken = [];
        let huidigeDatum = new Date(datum_van + "T00:00:00");
        const stopDatum = new Date(eindDatum + "T00:00:00");
        while (huidigeDatum <= stopDatum) {
            const d = huidigeDatum.getFullYear() + "-" + String(huidigeDatum.getMonth() + 1).padStart(2, "0") + "-" + String(huidigeDatum.getDate()).padStart(2, "0");
            const data = new FormData();
            data.append("action", "gbh_blokkade_opslaan");
            data.append("gbh_nonce", gbhNonce);
            data.append("datum", d);
            data.append("hele_dag", hele_dag);
            data.append("tijd_van", tijd_van);
            data.append("tijd_tot", tijd_tot);
            taken.push(fetch(ajaxUrl, { method: "POST", body: data }));
            huidigeDatum.setDate(huidigeDatum.getDate() + 1);
        }
        Promise.all(taken.map(p => p.then(r => r.json()))).then(results => {
            const fouten = results.filter(r => !r.success);
            if (fouten.length > 0) {
                msg.style.color = "#c62828";
                msg.textContent = "Sommige datums konden niet worden opgeslagen.";
                return;
            }
            msg.style.color = "#2e7d32";
            msg.textContent = "Blokkade(s) opgeslagen!";
            document.getElementById("gbh-blok-datum").value = "";
            document.getElementById("gbh-blok-datum-tot").value = "";
            document.getElementById("gbh-blok-van").value = "";
            document.getElementById("gbh-blok-tot").value = "";
            document.getElementById("gbh-blok-heledag").checked = false;
            document.getElementById("gbh-blok-tijden").style.display = "flex";
            document.getElementById("gbh-blok-datum-tot-wrap").style.display = "none";

            // Zorg dat er altijd een tabel is
            const lijstDiv = document.getElementById("gbh-blokkades-lijst");
            if (!lijstDiv.querySelector("tbody")) {
                lijstDiv.innerHTML = "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\"><thead><tr style=\"background:#fdecea;\"><th style=\"padding:8px;text-align:left;\">Datum</th><th style=\"padding:8px;text-align:left;\">Tijd</th><th style=\"padding:8px;\"></th></tr></thead><tbody></tbody></table>";
            }
            const tbl = lijstDiv.querySelector("tbody");
            let huidigeDatum2 = new Date(datum_van);
            const stopDatum2 = new Date(eindDatum);
            let i = 0;
            while (huidigeDatum2 <= stopDatum2) {
                const d = huidigeDatum2.toISOString().split("T")[0];
                const parts = d.split("-");
                const datumNl = parts[2] + "-" + parts[1] + "-" + parts[0];
                const tijdStr = hele_dag ? "Hele dag" : tijd_van + " - " + tijd_tot;
                const nieuweId = results[i] && results[i].data ? results[i].data.id : null;
                const tr = document.createElement("tr");
                tr.style.borderBottom = "1px solid #eee";
                tr.innerHTML = "<td style=\"padding:8px;\">" + datumNl + "</td><td style=\"padding:8px;\">" + tijdStr + "</td><td style=\"padding:8px;text-align:right;\"><button type=\"button\" style=\"padding:4px 12px;border:0;border-radius:6px;background:#c62828;color:#fff;cursor:pointer;font-size:13px;\">Verwijderen</button></td>";
                koppelVerwijderKnop(tr.querySelector("button"), tr, nieuweId);
                tbl.appendChild(tr);
                huidigeDatum2.setDate(huidigeDatum2.getDate() + 1);
                i++;
            }
        });
    });
   function koppelVerwijderKnop(btn, tr, nieuweId) {
        btn.addEventListener("click", function() {
            if (!confirm("Blokkade verwijderen?")) return;
            const id = nieuweId || btn.dataset.id;
            const data = new FormData();
            data.append("action", "gbh_blokkade_verwijderen");
            data.append("gbh_nonce", gbhNonce);
            data.append("id", id);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    tr.remove();
                    const msg = document.getElementById("gbh-blok-msg");
                    msg.style.color = "#2e7d32";
                    msg.textContent = "Blokkade verwijderd.";
                }
            });
        });
    }

    document.querySelectorAll(".gbh-blok-del").forEach(function(btn) {
        koppelVerwijderKnop(btn, btn.closest("tr"), null);
    });

    document.getElementById("gbh-logout-btn").addEventListener("click", function() {
        const data = new FormData();
        data.append("action", "gbh_logout");
        data.append("gbh_nonce", gbhNonce);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
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
        data.append("gbh_nonce", gbhNonce);
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

document.querySelectorAll(".gbh-annuleer-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const id = btn.dataset.id;
            const form = document.getElementById("gbh-edit-" + id);
            form.style.display = "none";
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
            data.append("gbh_nonce", gbhNonce);
            data.append("id", id);
            data.append("naam", naam);
            data.append("email", email);
            data.append("telefoon", telefoon);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); });
        });
    });

document.querySelectorAll(".gbh-afspraak-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.getElementById("gbh-nieuw-afspraak-klant-id").value = btn.dataset.id;
            document.getElementById("gbh-nieuw-afspraak-naam").value = btn.dataset.naam;
            document.getElementById("gbh-nieuw-afspraak-email").value = btn.dataset.email;
            document.getElementById("gbh-nieuw-afspraak-telefoon").value = btn.dataset.telefoon;
            document.getElementById("gbh-nieuw-afspraak-datum").value = "";
            document.getElementById("gbh-nieuw-afspraak-tijd").value = "";
            document.getElementById("gbh-nieuw-afspraak-msg").textContent = "";
            document.querySelectorAll(".gbh-nieuw-behandeling").forEach(function(cb) { cb.checked = false; });
            document.getElementById("gbh-nieuw-afspraak-popup").style.display = "block";
            document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "block";
        });
    });
    var sluitBtn = document.getElementById("gbh-nieuw-afspraak-sluiten");
    if (sluitBtn) sluitBtn.addEventListener("click", function() {
        document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
        document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
    });

    var overlayNieuw = document.getElementById("gbh-nieuw-afspraak-overlay");
    if (overlayNieuw) overlayNieuw.addEventListener("click", function() {
        document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
        document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
    });

    var opslaanBtn = document.getElementById("gbh-nieuw-afspraak-opslaan");
    if (opslaanBtn) opslaanBtn.addEventListener("click", function() {
        const naam = document.getElementById("gbh-nieuw-afspraak-naam").value.trim();
        const email = document.getElementById("gbh-nieuw-afspraak-email").value.trim();
        const telefoon = document.getElementById("gbh-nieuw-afspraak-telefoon").value.trim();
        const datum = document.getElementById("gbh-nieuw-afspraak-datum").value;
        const tijd = document.getElementById("gbh-nieuw-afspraak-tijd").value;
        const msg = document.getElementById("gbh-nieuw-afspraak-msg");
        const gekozen = [];
        let behandeltijd = 0;
        let prijs = 0;
        document.querySelectorAll(".gbh-nieuw-behandeling").forEach(function(cb) {
            if (cb.checked) {
                gekozen.push(cb.dataset.naam);
                behandeltijd += parseInt(cb.dataset.tijd);
                prijs += parseFloat(cb.dataset.prijs);
            }
        });
        if (!datum || !tijd) { msg.style.color = "#c62828"; msg.textContent = "Vul datum en tijd in."; return; }
        if (gekozen.length === 0) { msg.style.color = "#c62828"; msg.textContent = "Kies minimaal één behandeling."; return; }
        const data = new FormData();
        data.append("action", "gbh_save_booking");
        data.append("gbh_nonce", gbhNonce);
        data.append("naam", naam);
        data.append("email", email);
        data.append("telefoon", telefoon);
        data.append("datum", datum);
        data.append("tijd", tijd);
        data.append("behandelingen", gekozen.join(", "));
        data.append("behandeltijd", behandeltijd);
        data.append("prijs", prijs);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                msg.style.color = "#2e7d32";
                msg.textContent = "Afspraak gemaakt!";
                setTimeout(function() {
                    document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
                    document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
                }, 1000);
            } else {
                msg.style.color = "#c62828";
                msg.textContent = res.data;
            }
        });
    });

    document.querySelectorAll(".gbh-del-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            if (!confirm("Weet je zeker dat je deze klant wilt verwijderen?")) return;
            const data = new FormData();
            data.append("action", "gbh_klant_verwijderen");
            data.append("gbh_nonce", gbhNonce);
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

        // Blokkades toevoegen aan bookings_list
        $blokkades_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades", ARRAY_A);
        $geblokkeerde_dagen = [];
        foreach ($blokkades_raw as $bl) {
            if ($bl['hele_dag']) {
                $geblokkeerde_dagen[] = $bl['datum'];
            } else {
                $van_ts = strtotime('1970-01-01 ' . substr($bl['tijd_van'], 0, 5));
                $tot_ts = strtotime('1970-01-01 ' . substr($bl['tijd_tot'], 0, 5));
                for ($t = $van_ts; $t < $tot_ts; $t += 15 * 60) {
                    $bookings_list[] = $bl['datum'] . ' ' . date('H:i', $t);
                }
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
        $nonce = wp_create_nonce('gbh_ajax_nonce');

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
@media(max-width:600px) {
    .gbh-summary-box { position:static; width:100%; box-sizing:border-box; box-shadow:none; margin-top:20px; }
}
.gbh-summary-box strong { color:#7d3c98; font-size:16px; }
.gbh-next-btn { display:block; width:100%; margin-top:14px; padding:12px; border:0; border-radius:8px; background:#7d3c98; color:#fff; cursor:pointer; font-size:15px; font-weight:600; }
.gbh-next-btn:hover { background:#6a2f82; }
h3.gbh-cat { color:#7d3c98; font-size:15px; margin:0 0 8px; border-bottom:2px solid #e8d5f5; padding-bottom:6px; }
.gbh-welkom { padding:10px 14px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; color:#2e7d32; font-weight:600; margin-bottom:12px; font-size:15px; display:none; }
@keyframes gbh-knipperen { 0%, 100% { opacity:1; } 50% { opacity:0; } }
@keyframes gbh-knipperen-tijd { 0%, 100% { opacity:1; } 50% { opacity:0; } }
</style>';

        echo '<div class="gbh-booking">';
        echo '<div style="text-align:right;margin-bottom:16px;"><a href="https://goodbyehair.nl/medewerker-login/" style="font-size:13px;color:#999;text-decoration:none;border:1px solid #ddd;border-radius:8px;padding:6px 12px;">Medewerker login</a></div>';
        echo '<h2>Kies je behandeling</h2>';
        echo '<div class="gbh-columns">';

        foreach ($treatments as $category => $items) {
            echo '<div class="gbh-col">';
            echo '<h3 class="gbh-cat">' . esc_html($category) . '</h3>';
            foreach ($items as $t) {
                echo '<label class="gbh-treatment-label">';
                echo '<input type="checkbox" class="gbh-treatment" data-time="' . esc_attr($t['time']) . '" data-price="' . esc_attr($t['price']) . '" data-name="' . esc_attr($t['name']) . '"> ';
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
    const boekingenVastgesteld = ' . json_encode($bookings_list) . ';
    let bookings = boekingenVastgesteld.slice();
    let geblokkeerde_dagen = ' . json_encode($geblokkeerde_dagen) . ';
    const gbhAjaxUrl = "' . $ajax_url . '";
    const gbhCalNonce = "' . $nonce . '";

    window.gbhSetBlokkades = function(dagen, slots) {
        geblokkeerde_dagen = dagen;
        bookings = boekingenVastgesteld.slice();
        slots.forEach(function(slot) {
            if (!bookings.includes(slot)) bookings.push(slot);
        });
    };
    window.gbhRenderCalendar = function() {
        renderCalendar();
    };
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
        volgendeBtn.style.animation = "none";
        const datumHeader = document.getElementById("gbh-datum-header");
        datumHeader.style.background = "#7d3c98";
        datumHeader.style.color = "#fff";
        datumHeader.style.fontSize = "18px";
        datumHeader.style.padding = "12px 20px";
        datumHeader.style.animation = "gbh-knipperen 2s step-start infinite";
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
           const monthValue = String(month + 1).padStart(2, "0");
            const dayValue = String(d).padStart(2, "0");
            const fullDate = year + "-" + monthValue + "-" + dayValue;
            const isGeblokkeerd = geblokkeerde_dagen.includes(fullDate);
            const isEnabled = enabled && !isPast && !isGeblokkeerd;
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
                    for (let t = new Date(startTs); t < endTs; t.setMinutes(t.getMinutes() + 15)) {
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
                        tijdHeader.style.animation = "none";
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
        echo '<label style="display:block;margin-bottom:10px;">Telefoon <span style="color:#c62828;">*</span><br><input type="tel" id="gbh-telefoon" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';

        echo '<button type="submit" id="gbh-bevestig" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;margin-top:10px;">Afspraak bevestigen</button>';
        echo '<button type="button" id="gbh-back-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-top:10px;margin-left:10px;">← Terug</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<script>
document.addEventListener("DOMContentLoaded", function () {
function positionSummary() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        box.style.top = Math.max(20, rect.top + window.pageYOffset + 20) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    }
    positionSummary();
    window.addEventListener("scroll", function() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        const centeredTop = (window.innerHeight / 2) - (box.offsetHeight / 2);
        box.style.top = Math.max(20, centeredTop) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    });
    window.addEventListener("resize", positionSummary);
    const checkboxes = document.querySelectorAll(".gbh-treatment");
    const totalTime = document.getElementById("gbh-total-time");
    const totalPrice = document.getElementById("gbh-total-price");
    const nextButton = document.getElementById("gbh-next-step");
    const step1 = document.querySelector(".gbh-booking");
    const step2 = document.getElementById("gbh-step-2");
    const step3 = document.getElementById("gbh-step-3");
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . $nonce . '";
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
            const blokData = new FormData();
            blokData.append("action", "gbh_get_blokkades");
            blokData.append("gbh_nonce", gbhNonce);
            fetch(ajaxUrl, { method: "POST", body: blokData })
            .then(r => r.json())
            .then(function(res) {
                if (res.success && window.gbhSetBlokkades) {
                    window.gbhSetBlokkades(res.data.geblokkeerde_dagen, res.data.geblokkeerde_slots);
                }
                step1.style.display = "none";
                step2.style.display = "block";
                if (window.gbhRenderCalendar) window.gbhRenderCalendar();
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
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

    // Klantherkenning via localStorage
    const emailInput = document.getElementById("gbh-email");
    const telefoonInput = document.getElementById("gbh-telefoon");
    if (telefoonInput) {
        telefoonInput.addEventListener("input", function() {
            telefoonInput.setCustomValidity("");
        });
    }
    const gbhOpgeslaanNaam     = localStorage.getItem("gbh_naam");
    const gbhOpgeslaanEmail    = localStorage.getItem("gbh_email");
    const gbhOpgeslaanTelefoon = localStorage.getItem("gbh_telefoon");
    if (gbhOpgeslaanNaam && gbhOpgeslaanEmail) {
        document.getElementById("gbh-naam").value     = gbhOpgeslaanNaam;
        document.getElementById("gbh-email").value    = gbhOpgeslaanEmail;
        document.getElementById("gbh-telefoon").value = gbhOpgeslaanTelefoon;
        const welkom = document.getElementById("gbh-welkom");
        welkom.style.display = "block";
        welkom.textContent = "Welkom terug, " + gbhOpgeslaanNaam + "! Je gegevens zijn ingevuld.";
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
                if (cb.checked) behandelingen.push(cb.dataset.name);
            });
            if (!naam || !email || !datum || !tijd) {
                return;
            }
            const telCijfers = telefoon.replace(/[\s\-]/g, "");
            const telInput = document.getElementById("gbh-telefoon");
          if (telCijfers[0] !== "0" || !/^\d{10}$/.test(telCijfers)) {
                telInput.setCustomValidity("Geef een Nederlands telefoonnummer van 10 cijfers, beginnend met 0.");
                telInput.reportValidity();
                return;
            }
            telInput.setCustomValidity("");
            const data = new FormData();
            data.append("action", "gbh_save_booking");
            data.append("gbh_nonce", gbhNonce);
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
                    localStorage.setItem("gbh_naam", naam);
                    localStorage.setItem("gbh_email", email);
                    localStorage.setItem("gbh_telefoon", telefoon);
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

        $boek = $wpdb->get_row($wpdb->prepare("SELECT behandelingen FROM {$wpdb->prefix}gbh_bookings WHERE id = %d", $id));
        $behandelingen_annuleer = $boek ? $boek->behandelingen : '';
        $timestamp = wp_next_scheduled('gbh_stuur_herinnering', [$id, $email, $naam, $datum, $tijd, $behandelingen_annuleer]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'gbh_stuur_herinnering', [$id, $email, $naam, $datum, $tijd, $behandelingen_annuleer]);
        }
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
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
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
        $tel_cijfers = preg_replace('/[\s\-]/', '', $telefoon);
        if (!preg_match('/^0\d{9}$/', $tel_cijfers)) {
            wp_send_json_error('Geef een geldig Nederlands telefoonnummer van 10 cijfers, beginnend met 0.');
        }

        $slots_nodig  = ceil($behandeltijd / 15) + 1;
        $nieuwe_start = strtotime('1970-01-01 ' . $tijd);
        $nieuwe_eind  = $nieuwe_start + ($slots_nodig * 15 * 60);
        $bestaande    = $wpdb->get_results($wpdb->prepare(
            "SELECT tijd, behandeltijd FROM $table WHERE datum = %s", $datum
        ));
        $blokkades    = $wpdb->get_results($wpdb->prepare(
            "SELECT hele_dag, tijd_van, tijd_tot FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s", $datum
        ));
        $bezet = false;
        foreach ($bestaande as $b) {
            $b_start = strtotime('1970-01-01 ' . substr($b->tijd, 0, 5));
            $b_eind  = $b_start + ((ceil($b->behandeltijd / 15) + 1) * 15 * 60);
            if ($nieuwe_start < $b_eind && $nieuwe_eind > $b_start) { $bezet = true; break; }
        }
        foreach ($blokkades as $bl) {
            if ($bl->hele_dag) { $bezet = true; break; }
            $bl_start = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bl_eind  = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            if ($nieuwe_start < $bl_eind && $nieuwe_eind > $bl_start) { $bezet = true; break; }
        }
        if ($bezet) {
            wp_send_json_error('Dit tijdslot is helaas net bezet geraakt. Kies een ander tijdstip.');
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
        $boeking_id = $wpdb->insert_id;
        // E-mail naar klant
        $onderwerp_klant = 'Bevestiging afspraak GoodByeHair';
       $headers_klant = ['Content-Type: text/html; charset=UTF-8'];
        $bericht_klant  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht_klant .= "Beste " . $naam . ",<br><br>";
        $bericht_klant .= "Je afspraak is bevestigd!<br><br>";
        $bericht_klant .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht_klant .= "Tijd: " . $tijd . "<br>";
        $bericht_klant .= "Behandelingen: " . $behandelingen . "<br>";
        $bericht_klant .= "Behandeltijd: " . $behandeltijd . " minuten<br><br>";
        $bericht_klant .= "Tot dan!<br><br>";
        $bericht_klant .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, $onderwerp_klant, $bericht_klant, $headers_klant);

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
        $afspraak_timestamp    = get_gmt_from_date($datum . ' ' . $tijd, 'U');
        $herinnering_timestamp = $afspraak_timestamp - (24 * 60 * 60);
        if ($herinnering_timestamp > time()) {
            wp_schedule_single_event($herinnering_timestamp, 'gbh_stuur_herinnering', [$boeking_id, $email, $naam, $datum, $tijd, $behandelingen]);
        }

        wp_send_json_success('Afspraak opgeslagen.');
    }

public function test_herinnering() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_test_herinnering_nonce');
        $email = get_option('gbh_salon_email', 'info@goodbyehair.nl');
        $this->stuur_herinnering(0, $email, 'Test Klant', date('Y-m-d', strtotime('+1 day')), '10:00', 'Oksels');
        wp_redirect(admin_url('admin.php?page=gbh-settings&test_verstuurd=1'));
        exit;
    }
    
    public function stuur_herinnering($booking_id, $email, $naam, $datum, $tijd, $behandelingen) {
        $onderwerp = 'Herinnering afspraak GoodByeHair';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht .= "Beste " . $naam . ",<br><br>";
        $bericht .= "Dit is een herinnering voor je afspraak van morgen!<br><br>";
        $bericht .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht .= "Tijd: " . $tijd . "<br>";
        $bericht .= "Behandelingen: " . $behandelingen . "<br><br>";
        $bericht .= "Tot morgen!<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, $onderwerp, $bericht, $headers);
    }

public function register_settings() {
        register_setting('gbh_settings_group', 'gbh_days');
        register_setting('gbh_settings_group', 'gbh_times');
        register_setting('gbh_settings_group', 'gbh_salon_email');
        register_setting('gbh_settings_group', 'gbh_medewerker_user');
       add_action('admin_post_gbh_sla_wachtwoord_op', [$this, 'sla_wachtwoord_op']);
    add_action('admin_post_gbh_test_herinnering', [$this, 'test_herinnering']);
    }

public function sla_wachtwoord_op() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_wachtwoord_nonce');
        $nieuw = $_POST['gbh_medewerker_pass_nieuw'] ?? '';
        $user  = sanitize_text_field($_POST['gbh_medewerker_user'] ?? '');
        if ($user) update_option('gbh_medewerker_user', $user);
        if (!empty($nieuw)) {
            update_option('gbh_medewerker_pass', password_hash($nieuw, PASSWORD_DEFAULT));
            
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
        <?php if (isset($_GET['test_verstuurd'])) : ?>
                <div class="notice notice-success"><p>Test herinneringsmail verstuurd!</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gbh_test_herinnering">
                <?php wp_nonce_field('gbh_test_herinnering_nonce'); ?>
                <button type="submit" style="padding:8px 16px;border:0;border-radius:6px;background:#7d3c98;color:#fff;cursor:pointer;">Stuur test herinneringsmail</button>
            </form>
            <br>
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

   // Blokkades tabel
    $blokkades = $wpdb->prefix . 'gbh_blokkades';
    $sql3 = "CREATE TABLE IF NOT EXISTS $blokkades (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        datum date NOT NULL,
        tijd_van time DEFAULT NULL,
        tijd_tot time DEFAULT NULL,
        hele_dag tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

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
        add_action('wp_ajax_gbh_blokkade_opslaan', [$this, 'blokkade_opslaan']);
        add_action('wp_ajax_nopriv_gbh_blokkade_opslaan', [$this, 'blokkade_opslaan']);
        add_action('wp_ajax_gbh_blokkade_verwijderen', [$this, 'blokkade_verwijderen']);
        add_action('wp_ajax_nopriv_gbh_blokkade_verwijderen', [$this, 'blokkade_verwijderen']);
        add_action('wp_ajax_gbh_get_blokkades', [$this, 'get_blokkades']);
        add_action('wp_ajax_nopriv_gbh_get_blokkades', [$this, 'get_blokkades']);
        add_action('wp_ajax_gbh_get_week_data', [$this, 'get_week_data']);
        add_action('wp_ajax_nopriv_gbh_get_week_data', [$this, 'get_week_data']);
        add_action('wp_ajax_gbh_wijzig_afspraak', [$this, 'wijzig_afspraak']);
        add_action('wp_ajax_nopriv_gbh_wijzig_afspraak', [$this, 'wijzig_afspraak']);
        add_action('wp_ajax_gbh_verwijder_afspraak', [$this, 'verwijder_afspraak']);
        add_action('wp_ajax_nopriv_gbh_verwijder_afspraak', [$this, 'verwijder_afspraak']);
        add_filter('wp_mail_from', function($email) { return 'info@goodbyehair.nl'; });
        add_filter('wp_mail_from_name', function($name) { return 'Goodbyehair'; });
    }

   // -------------------------
    // BLOKKADE OPSLAAN
    // -------------------------

     // -------------------------
    // WEEK DATA OPHALEN
    // -------------------------
    public function get_week_data() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $week_start = sanitize_text_field($_POST['week_start'] ?? '');
        if (!$week_start) wp_send_json_error('Geen datum.');

        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));

        $afspraken = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_bookings WHERE datum >= %s AND datum <= %s ORDER BY datum ASC, tijd ASC",
            $week_start, $week_end
        ));

        $blokkades = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_blokkades WHERE datum >= %s AND datum <= %s ORDER BY datum ASC, tijd_van ASC",
            $week_start, $week_end
        ));

        wp_send_json_success([
            'afspraken' => $afspraken,
            'blokkades' => $blokkades,
        ]);
    }

    // -------------------------
    // AFSPRAAK WIJZIGEN
    // -------------------------
    public function wijzig_afspraak() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id            = intval($_POST['id'] ?? 0);
        $naam          = sanitize_text_field($_POST['naam'] ?? '');
        $email         = sanitize_email($_POST['email'] ?? '');
        $telefoon      = sanitize_text_field($_POST['telefoon'] ?? '');
        $datum         = sanitize_text_field($_POST['datum'] ?? '');
        $tijd          = sanitize_text_field($_POST['tijd'] ?? '');
        $behandelingen = sanitize_text_field($_POST['behandelingen'] ?? '');
        $behandeltijd  = intval($_POST['behandeltijd'] ?? 0);
        $prijs         = floatval($_POST['prijs'] ?? 0);

        if (!$id || !$naam || !$email || !$datum || !$tijd) {
            wp_send_json_error('Vul alle velden in.');
        }

        // Controleer of het nieuwe tijdslot bezet is (exclusief de huidige afspraak zelf)
        $slots_nodig  = ceil($behandeltijd / 15) + 1;
        $nieuwe_start = strtotime('1970-01-01 ' . $tijd);
        $nieuwe_eind  = $nieuwe_start + ($slots_nodig * 15 * 60);

        $bestaande = $wpdb->get_results($wpdb->prepare(
            "SELECT tijd, behandeltijd FROM {$wpdb->prefix}gbh_bookings WHERE datum = %s AND id != %d",
            $datum, $id
        ));
        $blokkades = $wpdb->get_results($wpdb->prepare(
            "SELECT hele_dag, tijd_van, tijd_tot FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s",
            $datum
        ));

        $bezet = false;
        foreach ($bestaande as $b) {
            $b_start = strtotime('1970-01-01 ' . substr($b->tijd, 0, 5));
            $b_eind  = $b_start + ((ceil($b->behandeltijd / 15) + 1) * 15 * 60);
            if ($nieuwe_start < $b_eind && $nieuwe_eind > $b_start) { $bezet = true; break; }
        }
        foreach ($blokkades as $bl) {
            if ($bl->hele_dag) { $bezet = true; break; }
            $bl_start = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bl_eind  = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            if ($nieuwe_start < $bl_eind && $nieuwe_eind > $bl_start) { $bezet = true; break; }
        }

        if ($bezet) {
            wp_send_json_error('Dit tijdslot is al bezet. Kies een ander tijdstip.');
        }

        $wpdb->update(
            $wpdb->prefix . 'gbh_bookings',
            compact('naam', 'email', 'telefoon', 'datum', 'tijd', 'behandelingen', 'behandeltijd', 'prijs'),
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f'],
            ['%d']
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht .= "Beste " . $naam . ",<br><br>";
        $bericht .= "Je afspraak is gewijzigd.<br><br>";
        $bericht .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht .= "Tijd: " . $tijd . "<br>";
        $bericht .= "Behandelingen: " . $behandelingen . "<br>";
        $bericht .= "Behandeltijd: " . $behandeltijd . " minuten<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, 'Wijziging afspraak GoodByeHair', $bericht, $headers);

        wp_send_json_success('Afspraak gewijzigd.');
    }

    // -------------------------
    // AFSPRAAK VERWIJDEREN
    // -------------------------
    public function verwijder_afspraak() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Geen ID.');

        $boek = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_bookings WHERE id = %d", $id
        ));
        if (!$boek) wp_send_json_error('Afspraak niet gevonden.');

        $wpdb->delete($wpdb->prefix . 'gbh_bookings', ['id' => $id], ['%d']);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = "Beste " . $boek->naam . ",<br><br>";
        $bericht .= "Je afspraak op " . date('d-m-Y', strtotime($boek->datum)) . " om " . substr($boek->tijd, 0, 5) . " is geannuleerd.<br><br>";
        $bericht .= "Neem contact met ons op om een nieuwe afspraak te maken.<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair";
        wp_mail($boek->email, 'Afspraak geannuleerd - GoodByeHair', $bericht, $headers);

        wp_send_json_success('Afspraak verwijderd.');
    }   
    public function blokkade_opslaan() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $datum     = sanitize_text_field($_POST['datum'] ?? '');
        $hele_dag  = intval($_POST['hele_dag'] ?? 0);
        $tijd_van  = sanitize_text_field($_POST['tijd_van'] ?? '');
        $tijd_tot  = sanitize_text_field($_POST['tijd_tot'] ?? '');
        if (!$datum) wp_send_json_error('Kies een datum.');
        if (!$hele_dag && (!$tijd_van || !$tijd_tot)) wp_send_json_error('Vul een tijd van en tot in.');
        $bestaande = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s", $datum
        ));
        foreach ($bestaande as $bl) {
            if ($bl->hele_dag) {
                wp_send_json_error('Deze dag is al volledig geblokkeerd.');
            }
            if ($hele_dag) {
                wp_send_json_error('Er bestaan al tijdblokken op deze dag. Verwijder die eerst.');
            }
            $bestaand_van = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bestaand_tot = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            $nieuw_van    = strtotime('1970-01-01 ' . $tijd_van);
            $nieuw_tot    = strtotime('1970-01-01 ' . $tijd_tot);
            if ($nieuw_van < $bestaand_tot && $nieuw_tot > $bestaand_van) {
                wp_send_json_error('Dit tijdblok overlapt met een bestaande blokkade.');
            }
        }
        $wpdb->insert($wpdb->prefix . 'gbh_blokkades', [
            'datum'    => $datum,
            'hele_dag' => $hele_dag,
            'tijd_van' => $hele_dag ? null : $tijd_van,
            'tijd_tot' => $hele_dag ? null : $tijd_tot,
        ]);
        wp_send_json_success(['bericht' => 'Blokkade opgeslagen.', 'id' => $wpdb->insert_id]);
    }

    // -------------------------
    // BLOKKADE VERWIJDEREN
    // -------------------------
    public function blokkade_verwijderen() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gbh_blokkades', ['id' => $id]);
        wp_send_json_success('Blokkade verwijderd.');
    }
// -------------------------
    // BLOKKADES OPHALEN
    // -------------------------
    public function get_blokkades() {
        global $wpdb;
        $blokkades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades", ARRAY_A);
        $geblokkeerde_dagen = [];
        $geblokkeerde_slots = [];
        foreach ($blokkades as $bl) {
            if ($bl['hele_dag']) {
                $geblokkeerde_dagen[] = $bl['datum'];
            } else {
                $van_ts = strtotime('1970-01-01 ' . substr($bl['tijd_van'], 0, 5));
                $tot_ts = strtotime('1970-01-01 ' . substr($bl['tijd_tot'], 0, 5));
                for ($t = $van_ts; $t < $tot_ts; $t += 15 * 60) {
                    $geblokkeerde_slots[] = $bl['datum'] . ' ' . date('H:i', $t);
                }
            }
        }
        wp_send_json_success([
            'geblokkeerde_dagen' => $geblokkeerde_dagen,
            'geblokkeerde_slots' => $geblokkeerde_slots,
        ]);
    }
     // -------------------------
    // LOGIN / LOGOUT (eigen systeem)
    // -------------------------
        private function gbh_is_ingelogd() {

      if (empty($_COOKIE['gbh_medewerker'])) return false;

        $cookie = sanitize_text_field(wp_unslash($_COOKIE['gbh_medewerker']));
        if (strpos($cookie, '|') === false) return false;

        list($session_id, $token) = explode('|', $cookie, 2);
        if (!$session_id || !$token) return false;

        $sessions = get_option('gbh_medewerker_tokens', []);
        if (!is_array($sessions) || empty($sessions[$session_id])) return false;

        $session = $sessions[$session_id];

        if (empty($session['token']) || empty($session['expires'])) return false;

        if (time() > intval($session['expires'])) {
            unset($sessions[$session_id]);
            update_option('gbh_medewerker_tokens', $sessions);
            return false;
        }

        return hash_equals($session['token'], hash('sha256', $token));
    }

        public function handle_login() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
        wp_send_json_error('Ongeldige aanvraag.');
    }

    $username = strtolower(sanitize_text_field($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $opgeslagen_user = strtolower(get_option('gbh_medewerker_user', ''));
    $opgeslagen_pass = get_option('gbh_medewerker_pass', '');

    $pogingen_key = 'gbh_login_pogingen_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $pogingen = (int) get_transient($pogingen_key);
    if ($pogingen >= 5) {
        wp_send_json_error('Te veel inlogpogingen. Probeer het over 15 minuten opnieuw.');
    }
    if ($username !== $opgeslagen_user || !password_verify($password, $opgeslagen_pass)) {
        set_transient($pogingen_key, $pogingen + 1, 15 * 60);
        wp_send_json_error('Gebruikersnaam of wachtwoord onjuist.');
    }
    delete_transient($pogingen_key);

    $session_id = bin2hex(random_bytes(16));
    $token      = bin2hex(random_bytes(32));
    $expires    = time() + (8 * 60 * 60);

    $sessions = get_option('gbh_medewerker_tokens', []);
    if (!is_array($sessions)) $sessions = [];

    foreach ($sessions as $key => $session) {
        if (empty($session['expires']) || time() > intval($session['expires'])) {
            unset($sessions[$key]);
        }
    }

    $sessions[$session_id] = [
        'token'   => hash('sha256', $token),
        'expires' => $expires,
    ];
    update_option('gbh_medewerker_tokens', $sessions);

    $cookie_waarde = $session_id . '|' . $token;

    setcookie('gbh_medewerker', $cookie_waarde, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    wp_send_json_success('Ingelogd');
}

public function handle_logout() {
    // Nonce check toegevoegd (ontbrak eerder)
    if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
        wp_send_json_error('Ongeldige aanvraag.');
    }

    $sessions = get_option('gbh_medewerker_tokens', []);
    if (!is_array($sessions)) $sessions = [];

    // Huidige sessie verwijderen uit opgeslagen sessies
    if (!empty($_COOKIE['gbh_medewerker'])) {
        $cookie = sanitize_text_field(wp_unslash($_COOKIE['gbh_medewerker']));
        if (strpos($cookie, '|') !== false) {
            list($session_id) = explode('|', $cookie, 2);
            if (!empty($sessions[$session_id])) {
                unset($sessions[$session_id]);
                update_option('gbh_medewerker_tokens', $sessions);
            }
        }
    }

    // Cookie verwijderen met dezelfde opties als bij het instellen
    $cookie_opties = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',        // moet exact hetzelfde zijn als bij instellen
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if (!headers_sent()) {
        setcookie('gbh_medewerker', '', $cookie_opties);
    }

    wp_send_json_success('Uitgelogd');
}
    // -------------------------
    // KLANT OPSLAAN (medewerker)
    // -------------------------
   public function klant_opslaan() {
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
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
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $aantal = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gbh_bookings WHERE klant_id = %d", $id
        ));
        if ($aantal > 0) {
            wp_send_json_error('Deze klant heeft nog ' . $aantal . ' afspraak/afspraken. Verwijder eerst de afspraken.');
        }
        $wpdb->delete($wpdb->prefix . 'gbh_klanten', ['id' => $id]);
        wp_send_json_success('Verwijderd.');
    }

    // -------------------------
    // FRONTEND MEDEWERKER PANEL
    // -------------------------
    public function render_medewerker() {
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('gbh_ajax_nonce');
        ob_start();
        echo '<div id="gbh-medewerker-wrap">';

        if (!$this->gbh_is_ingelogd() && !current_user_can('manage_options')) {
            // Loginformulier
            echo '<div id="gbh-login-form" style="max-width:360px;margin:0 auto;padding:24px;border:2px solid #7d3c98;border-radius:12px;background:#faf5ff;">';
            echo '<h2 style="color:#7d3c98;margin-top:0;">Medewerker login</h2>';
            echo '<div id="gbh-login-error" style="color:#c62828;margin-bottom:10px;display:none;"></div>';
            echo '<label style="display:block;margin-bottom:10px;">Gebruikersnaam<br><input type="text" id="gbh-login-user" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"></label>';
            echo '<label style="display:block;margin-bottom:16px;">Wachtwoord<br><div style="position:relative;"><input type="password" id="gbh-login-pass" style="width:100%;padding:10px;padding-right:44px;border:1px solid #ccc;border-radius:8px;margin-top:4px;box-sizing:border-box;"><button type="button" onclick="const p=document.getElementById(\'gbh-login-pass\');p.type=p.type===\'password\'?\'text\':\'password\';" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#999;font-size:13px;">Toon</button></div></label>';
            echo '<button type="button" id="gbh-login-btn" style="width:100%;padding:12px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;font-size:15px;font-weight:600;">Inloggen</button>';
            echo '</div>';
           echo '<script>
function gbhKoppelLogin() {
    var btn = document.getElementById("gbh-login-btn");
    if (!btn) { setTimeout(gbhKoppelLogin, 100); return; }
    btn.addEventListener("click", function() {
        const user = document.getElementById("gbh-login-user").value;
        const pass = document.getElementById("gbh-login-pass").value;
        const error = document.getElementById("gbh-login-error");
        const data = new FormData();
        data.append("action", "gbh_login");
        data.append("gbh_nonce", "' . $nonce . '");
        data.append("username", user);
        data.append("password", pass);
       fetch("' . $ajax_url . '", { method: "POST", body: data, credentials: "same-origin" })
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
}
gbhKoppelLogin();
</script>';
        } else {
            // Dashboard
            global $wpdb;
            $klanten = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_klanten ORDER BY naam ASC");
            $medewerker_naam = get_option('gbh_medewerker_user', 'medewerker');

            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">';
            echo '<h2 style="color:#7d3c98;margin:0;">Dashboard</h2>';
            echo '<div style="display:flex;gap:10px;align-items:center;">';
            echo '<span style="font-size:14px;color:#666;">Ingelogd als: <strong>' . esc_html($medewerker_naam) . '</strong></span>';
            echo '<button type="button" id="gbh-logout-btn" style="padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Uitloggen</button>';
            echo '</div>';
            echo '</div>';

            echo '<div id="gbh-dashboard" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:30px;">';
            echo '<button type="button" id="gbh-dash-blokkeren" style="padding:20px 30px;border:0;border-radius:12px;background:#c62828;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">📅 Tijd blokkeren</button>';
            echo '<button type="button" id="gbh-dash-klanten" style="padding:20px 30px;border:0;border-radius:12px;background:#7d3c98;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">👥 Klantenbestand</button>';
            echo '<button type="button" id="gbh-dash-agenda" style="padding:20px 30px;border:0;border-radius:12px;background:#1565c0;color:#fff;cursor:pointer;font-size:16px;font-weight:600;">🗓 Agendaoverzicht</button>';
            echo '</div>';

            echo '<div id="gbh-sectie-blokkeren" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';

            // Blokkades paneel direct na header
            $blokkades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades ORDER BY datum ASC, tijd_van ASC");
            echo '<div id="gbh-blok-paneel" style="margin-bottom:24px;padding:16px;border:2px solid #c62828;border-radius:12px;background:#fff8f8;">';
            echo '<h3 style="color:#c62828;margin-top:0;">Tijd blokkeren</h3>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">';
            echo '<div><label style="font-size:13px;">Datum van<br><input type="date" id="gbh-blok-datum" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label></div>';
            echo '<div id="gbh-blok-datum-tot-wrap" style="display:none;"><label style="font-size:13px;">Datum tot en met<br><input type="date" id="gbh-blok-datum-tot" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label></div>';
            echo '<div><label style="font-size:13px;"><input type="checkbox" id="gbh-blok-heledag" style="margin-right:6px;">Hele dag</label></div>';
            echo '<div id="gbh-blok-tijden" style="display:flex;gap:10px;">';
            echo '<label style="font-size:13px;">Van<br><input type="time" id="gbh-blok-van" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tot<br><input type="time" id="gbh-blok-tot" style="padding:8px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
            echo '</div>';
            echo '<button type="button" id="gbh-blok-btn" style="padding:10px 18px;border:0;border-radius:8px;background:#c62828;color:#fff;cursor:pointer;font-weight:600;">Blokkeren</button>';
            echo '<button type="button" id="gbh-blok-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;color:#000;cursor:pointer;">Terug</button>';
            echo '</div>';
            echo '<div id="gbh-blok-msg" style="font-size:14px;margin-bottom:10px;"></div>';
            echo '<div id="gbh-blokkades-lijst">';
            if ($blokkades) {
                echo '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
                echo '<thead><tr style="background:#fdecea;"><th style="padding:8px;text-align:left;">Datum</th><th style="padding:8px;text-align:left;">Tijd</th><th style="padding:8px;"></th></tr></thead>';
                echo '<tbody>';
                foreach ($blokkades as $bl) {
                    $datum_nl = date('d-m-Y', strtotime($bl->datum));
                    $tijd_str = $bl->hele_dag ? 'Hele dag' : substr($bl->tijd_van, 0, 5) . ' - ' . substr($bl->tijd_tot, 0, 5);
                    echo '<tr style="border-bottom:1px solid #eee;">';
                    echo '<td style="padding:8px;">' . esc_html($datum_nl) . '</td>';
                    echo '<td style="padding:8px;">' . esc_html($tijd_str) . '</td>';
                    echo '<td style="padding:8px;text-align:right;"><button type="button" class="gbh-blok-del" data-id="' . esc_attr($bl->id) . '" style="padding:4px 12px;border:0;border-radius:6px;background:#c62828;color:#fff;cursor:pointer;font-size:13px;">Verwijderen</button></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p style="color:#999;font-size:14px;">Geen blokkades.</p>';
            }
           echo '</div>';
            echo '</div>';
            echo '</div>'; // einde gbh-sectie-blokkeren

            echo '<div id="gbh-sectie-klanten" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';

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
                    echo '<button type="button" class="gbh-afspraak-btn" data-id="' . esc_attr($k->id) . '" data-naam="' . esc_attr($k->naam) . '" data-email="' . esc_attr($k->email) . '" data-telefoon="' . esc_attr($k->telefoon) . '" style="padding:6px 14px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;">Afspraak</button>';
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
                    echo '<button type="button" class="gbh-annuleer-btn" data-id="' . esc_attr($k->id) . '" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;color:#000;cursor:pointer;">Annuleren</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<p style="color:#999;">Nog geen klanten gevonden.</p>';
            }
            echo '</div>';
            echo '</div>'; // einde gbh-sectie-klanten

        echo '<div id="gbh-afspraak-nieuw-popup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #1565c0;border-radius:14px;padding:24px;z-index:9999;min-width:340px;max-width:560px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);">';
            echo '<h3 style="color:#1565c0;margin-top:0;">Nieuwe afspraak</h3>';
            echo '<input type="hidden" id="gbh-nieuw-afspraak-klant-id">';
            echo '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">';
            echo '<label style="font-size:13px;">Naam<br><input type="text" id="gbh-nieuw-afspraak-naam" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Email<br><input type="email" id="gbh-nieuw-afspraak-email" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Telefoon<br><input type="tel" id="gbh-nieuw-afspraak-telefoon" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Datum<br><input type="date" id="gbh-nieuw-afspraak-datum" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tijd<br><input type="time" id="gbh-nieuw-afspraak-tijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '</div>';
            echo '<strong style="font-size:13px;">Behandelingen</strong>';
            echo '<div style="display:flex;flex-direction:column;gap:4px;margin:8px 0 12px 0;">';
            $behandelingen_lijst = [
                'Gezicht' => [
                    'Bovenlip' => [15, 19], 'Kin' => [15, 19], 'Kaaklijn' => [15, 35],
                    'Nek' => [15, 25], 'Hals' => [15, 25], 'Wangen' => [15, 19], 'Gehele gezicht' => [20, 75],
                ],
                'Lichaam' => [
                    'Oksels' => [20, 39], 'Onderarm' => [15, 49], 'Bovenarm' => [15, 49],
                    'Armen geheel' => [30, 89], 'Borst' => [30, 35], 'Tepels rondom' => [15, 19],
                    'Buik' => [20, 49], 'Navelstrook' => [20, 19], 'Onderrug' => [20, 49],
                    'Bovenrug' => [20, 49], 'Rug geheel' => [30, 89], 'Bikinilijn klein' => [15, 25],
                    'Bikinilijn groot' => [20, 55], 'Onderbenen' => [20, 65], 'Bovenbenen' => [20, 65],
                    'Benen geheel' => [30, 119],
                ],
            ];
            foreach ($behandelingen_lijst as $cat => $items) {
                echo '<strong style="font-size:12px;color:#666;margin-top:6px;">' . esc_html($cat) . '</strong>';
                foreach ($items as $naam => $info) {
                    echo '<label style="font-size:13px;display:flex;align-items:center;gap:8px;">';
                    echo '<input type="checkbox" class="gbh-nieuw-behandeling" data-naam="' . esc_attr($naam) . '" data-tijd="' . esc_attr($info[0]) . '" data-prijs="' . esc_attr($info[1]) . '"> ';
                    echo esc_html($naam) . ' (' . esc_html($info[0]) . ' min) — €' . esc_html($info[1]);
                    echo '</label>';
                }
            }
            echo '</div>';
            echo '<div id="gbh-nieuw-afspraak-msg" style="font-size:13px;margin-bottom:8px;"></div>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<button type="button" id="gbh-nieuw-afspraak-opslaan" style="padding:10px 18px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;font-weight:600;">Afspraak maken</button>';
            echo '<button type="button" id="gbh-nieuw-afspraak-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Annuleren</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>'; // sluit gbh-sectie-klanten

        echo '<div id="gbh-afspraak-nieuw-popup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #1565c0;border-radius:14px;padding:24px;z-index:9999;min-width:340px;max-width:560px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);">';

           echo '<div id="gbh-nieuw-afspraak-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:9998;"></div>';

           echo '<div id="gbh-sectie-agenda" style="display:none;">';
            echo '<button type="button" class="gbh-terug-dashboard" style="margin-bottom:16px;padding:8px 16px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Terug naar dashboard</button>';
            echo '<h3 style="color:#1565c0;margin-top:0;">Agendaoverzicht</h3>';

            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:10px;flex-wrap:wrap;">';
            echo '<div style="display:flex;gap:8px;">';
            echo '<button type="button" id="gbh-week-prev" style="padding:8px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">← Vorige week</button>';
            echo '<button type="button" id="gbh-week-next" style="padding:8px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Volgende week →</button>';
            echo '</div>';
            echo '<span id="gbh-week-label" style="font-weight:600;font-size:16px;"></span>';
            echo '</div>';

            echo '<div id="gbh-week-kalender" style="overflow-x:auto;"></div>';

           echo '<div id="gbh-afspraak-popup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #1565c0;border-radius:14px;padding:24px;z-index:9999;min-width:320px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);">';
            echo '<h3 style="color:#1565c0;margin-top:0;" id="gbh-popup-titel">Afspraak bewerken</h3>';
            echo '<input type="hidden" id="gbh-popup-id">';
            echo '<div style="display:flex;flex-direction:column;gap:6px;">';
            echo '<label style="font-size:13px;">Naam<br><input type="text" id="gbh-popup-naam" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Email<br><input type="email" id="gbh-popup-email" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Telefoon<br><input type="tel" id="gbh-popup-telefoon" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Datum<br><input type="date" id="gbh-popup-datum" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Tijd<br><input type="time" id="gbh-popup-tijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Behandelingen<br><input type="text" id="gbh-popup-behandelingen" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Behandeltijd (min)<br><input type="number" id="gbh-popup-behandeltijd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '<label style="font-size:13px;">Prijs (€)<br><input type="number" step="0.01" id="gbh-popup-prijs" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin-top:4px;"></label>';
            echo '</div>';
            echo '<div id="gbh-popup-msg" style="margin-top:10px;font-size:13px;"></div>';
            echo '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">';
            echo '<button type="button" id="gbh-popup-opslaan" style="padding:10px 18px;border:0;border-radius:8px;background:#1565c0;color:#fff;cursor:pointer;font-weight:600;">Opslaan</button>';
            echo '<button type="button" id="gbh-popup-verwijderen" style="padding:10px 18px;border:0;border-radius:8px;background:#c62828;color:#fff;cursor:pointer;font-weight:600;">Verwijderen</button>';
            echo '<button type="button" id="gbh-popup-sluiten" style="padding:10px 18px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">Annuleren</button>';
            echo '</div>';
            echo '</div>';

            echo '<div id="gbh-popup-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:9998;"></div>';

            echo '</div>'; // sluit gbh-sectie-agenda

            echo '<script>';
(function() {
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . wp_create_nonce('gbh_ajax_nonce') . '";
    
    window.gbhLaadWeek = function() { laadWeek(); };

    function toonSectie(id) {
        document.getElementById("gbh-dashboard").style.display = "none";
        document.getElementById("gbh-sectie-blokkeren").style.display = "none";
        document.getElementById("gbh-sectie-klanten").style.display = "none";
        document.getElementById("gbh-sectie-agenda").style.display = "none";
        document.getElementById(id).style.display = "block";
    }
    const dagLabels = ["Ma","Di","Wo","Do","Vr","Za","Zo"];
    const today = new Date();
    today.setHours(0,0,0,0);

    function getMaandag(d) {
        const dag = new Date(d);
        const diff = dag.getDay() === 0 ? -6 : 1 - dag.getDay();
        dag.setDate(dag.getDate() + diff);
        dag.setHours(0,0,0,0);
        return dag;
    }

    let huidigeMaandag = getMaandag(new Date());

    function formatDatum(d) {
        return String(d.getDate()).padStart(2,"0") + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + d.getFullYear();
    }

    function isoDate(d) {
        return d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + String(d.getDate()).padStart(2,"0");
    }

    function laadWeek() {
        const weekStart = isoDate(huidigeMaandag);
        const zondag = new Date(huidigeMaandag);
        zondag.setDate(zondag.getDate() + 6);
        document.getElementById("gbh-week-label").textContent = formatDatum(huidigeMaandag) + " t/m " + formatDatum(zondag);

        const data = new FormData();
        data.append("action", "gbh_get_week_data");
        data.append("gbh_nonce", gbhNonce);
        data.append("week_start", weekStart);

        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            renderWeek(res.data.afspraken, res.data.blokkades);
        });
    }

    function renderWeek(afspraken, blokkades) {
        const wrap = document.getElementById("gbh-week-kalender");
        let html = "<div style=\"display:grid;grid-template-columns:repeat(7,1fr);gap:6px;min-width:560px;\">";

        for (let i = 0; i < 7; i++) {
            const dag = new Date(huidigeMaandag);
            dag.setDate(dag.getDate() + i);
            const dagIso = isoDate(dag);
            const isVandaag = dagIso === isoDate(today);

            html += "<div style=\"border:1px solid " + (isVandaag ? "#1565c0" : "#ddd") + ";border-radius:10px;padding:8px;background:" + (isVandaag ? "#e3f0ff" : "#fff") + ";min-height:120px;\">";
            html += "<div style=\"font-weight:600;font-size:13px;margin-bottom:6px;color:" + (isVandaag ? "#1565c0" : "#333") + ";\">" + dagLabels[i] + " " + formatDatum(dag) + "</div>";

            afspraken.forEach(function(a) {
                if (a.datum === dagIso) {
                    html += "<div class=\"gbh-agenda-afspraak\" data-id=\"" + a.id + "\" data-naam=\"" + a.naam.replace(/"/g,"&quot;") + "\" data-email=\"" + a.email.replace(/"/g,"&quot;") + "\" data-telefoon=\"" + a.telefoon.replace(/"/g,"&quot;") + "\" data-datum=\"" + a.datum + "\" data-tijd=\"" + a.tijd.substring(0,5) + "\" data-behandelingen=\"" + a.behandelingen.replace(/"/g,"&quot;") + "\" data-behandeltijd=\"" + a.behandeltijd + "\" data-prijs=\"" + a.prijs + "\" style=\"background:#7d3c98;color:#fff;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;cursor:pointer;\">";
                    html += "<strong>" + a.tijd.substring(0,5) + "</strong> " + a.naam + "<br><span style=\"font-size:11px;opacity:0.85;\">" + a.behandelingen + "</span>";
                    html += "</div>";
                }
            });

            blokkades.forEach(function(b) {
                if (b.datum === dagIso) {
                    const tijdStr = b.hele_dag == 1 ? "Hele dag" : b.tijd_van.substring(0,5) + " - " + b.tijd_tot.substring(0,5);
                    html += "<div style=\"background:#fdecea;color:#c62828;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;\">🚫 " + tijdStr + "</div>";
                }
            });

            html += "</div>";
        }

        html += "</div>";
        wrap.innerHTML = html;

        document.querySelectorAll(".gbh-agenda-afspraak").forEach(function(el) {
            el.addEventListener("click", function() {
                document.getElementById("gbh-popup-id").value = el.dataset.id;
                document.getElementById("gbh-popup-naam").value = el.dataset.naam;
                document.getElementById("gbh-popup-email").value = el.dataset.email;
                document.getElementById("gbh-popup-telefoon").value = el.dataset.telefoon;
                document.getElementById("gbh-popup-datum").value = el.dataset.datum;
                document.getElementById("gbh-popup-tijd").value = el.dataset.tijd;
                document.getElementById("gbh-popup-behandelingen").value = el.dataset.behandelingen;
                document.getElementById("gbh-popup-behandeltijd").value = el.dataset.behandeltijd;
                document.getElementById("gbh-popup-prijs").value = el.dataset.prijs;
                document.getElementById("gbh-popup-msg").textContent = "";
                document.getElementById("gbh-afspraak-popup").style.display = "block";
                document.getElementById("gbh-popup-overlay").style.display = "block";
            });
        });
    }

    document.getElementById("gbh-week-prev").addEventListener("click", function() {
        huidigeMaandag.setDate(huidigeMaandag.getDate() - 7);
        laadWeek();
    });

    document.getElementById("gbh-week-next").addEventListener("click", function() {
        huidigeMaandag.setDate(huidigeMaandag.getDate() + 7);
        laadWeek();
    });

    document.getElementById("gbh-popup-sluiten").addEventListener("click", function() {
        document.getElementById("gbh-afspraak-popup").style.display = "none";
        document.getElementById("gbh-popup-overlay").style.display = "none";
    });

    document.getElementById("gbh-popup-overlay").addEventListener("click", function() {
        document.getElementById("gbh-afspraak-popup").style.display = "none";
        document.getElementById("gbh-popup-overlay").style.display = "none";
    });

    document.getElementById("gbh-popup-opslaan").addEventListener("click", function() {
        const data = new FormData();
        data.append("action", "gbh_wijzig_afspraak");
        data.append("gbh_nonce", gbhNonce);
        data.append("id", document.getElementById("gbh-popup-id").value);
        data.append("naam", document.getElementById("gbh-popup-naam").value);
        data.append("email", document.getElementById("gbh-popup-email").value);
        data.append("telefoon", document.getElementById("gbh-popup-telefoon").value);
        data.append("datum", document.getElementById("gbh-popup-datum").value);
        data.append("tijd", document.getElementById("gbh-popup-tijd").value);
        data.append("behandelingen", document.getElementById("gbh-popup-behandelingen").value);
        data.append("behandeltijd", document.getElementById("gbh-popup-behandeltijd").value);
        data.append("prijs", document.getElementById("gbh-popup-prijs").value);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            const msg = document.getElementById("gbh-popup-msg");
            if (res.success) {
                msg.style.color = "#2e7d32";
                msg.textContent = "Opgeslagen!";
                setTimeout(function() {
                    document.getElementById("gbh-afspraak-popup").style.display = "none";
                    document.getElementById("gbh-popup-overlay").style.display = "none";
                    laadWeek();
                }, 800);
            } else {
                msg.style.color = "#c62828";
                msg.textContent = res.data;
            }
        });
    });

    document.getElementById("gbh-popup-verwijderen").addEventListener("click", function() {
        if (!confirm("Weet je zeker dat je deze afspraak wilt verwijderen?")) return;
        const data = new FormData();
        data.append("action", "gbh_verwijder_afspraak");
        data.append("gbh_nonce", gbhNonce);
        data.append("id", document.getElementById("gbh-popup-id").value);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById("gbh-afspraak-popup").style.display = "none";
                document.getElementById("gbh-popup-overlay").style.display = "none";
                laadWeek();
            }
        });
    });

  document.getElementById("gbh-dash-agenda").addEventListener("click", function() {
        toonSectie("gbh-sectie-agenda");
        window.gbhLaadWeek();
    });
})();
</script>';

            echo '</div>';

            echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . $nonce . '";

    function toonSectie(id) {
        document.getElementById("gbh-dashboard").style.display = "none";
        document.getElementById("gbh-sectie-blokkeren").style.display = "none";
        document.getElementById("gbh-sectie-klanten").style.display = "none";
        document.getElementById("gbh-sectie-agenda").style.display = "none";
        document.getElementById(id).style.display = "block";
    }

    document.getElementById("gbh-dash-blokkeren").addEventListener("click", function() { toonSectie("gbh-sectie-blokkeren"); });
    document.getElementById("gbh-dash-klanten").addEventListener("click", function() { toonSectie("gbh-sectie-klanten"); });
        document.querySelectorAll(".gbh-terug-dashboard").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.getElementById("gbh-sectie-blokkeren").style.display = "none";
            document.getElementById("gbh-sectie-klanten").style.display = "none";
            document.getElementById("gbh-sectie-agenda").style.display = "none";
            document.getElementById("gbh-dashboard").style.display = "flex";
        });
    });

       document.getElementById("gbh-blok-sluiten").addEventListener("click", function() {
        document.getElementById("gbh-blok-paneel").style.display = "none";
    });

    document.getElementById("gbh-blok-heledag").addEventListener("change", function() {
        document.getElementById("gbh-blok-tijden").style.display = this.checked ? "none" : "flex";
        document.getElementById("gbh-blok-datum-tot-wrap").style.display = this.checked ? "block" : "none";
        if (this.checked) {
            const datumVan = document.getElementById("gbh-blok-datum").value;
            if (datumVan) document.getElementById("gbh-blok-datum-tot").value = datumVan;
        }
    });

    document.getElementById("gbh-blok-btn").addEventListener("click", function() {
        const datum_van = document.getElementById("gbh-blok-datum").value;
        const hele_dag = document.getElementById("gbh-blok-heledag").checked ? 1 : 0;
        const datum_tot = hele_dag ? document.getElementById("gbh-blok-datum-tot").value : datum_van;
        const tijd_van = document.getElementById("gbh-blok-van").value;
        const tijd_tot = document.getElementById("gbh-blok-tot").value;
        const msg = document.getElementById("gbh-blok-msg");
        if (!datum_van) { msg.style.color = "#c62828"; msg.textContent = "Kies een datum."; return; }
        if (!hele_dag && (!tijd_van || !tijd_tot)) { msg.style.color = "#c62828"; msg.textContent = "Vul een tijd van en tot in."; return; }
        if (!hele_dag && tijd_van >= tijd_tot) { msg.style.color = "#c62828"; msg.textContent = "Eindtijd moet na begintijd liggen."; return; }
        if (hele_dag && datum_tot && datum_tot < datum_van) { msg.style.color = "#c62828"; msg.textContent = "Datum tot en met moet na datum van liggen."; return; }
        const eindDatum = datum_tot || datum_van;
        const taken = [];
        let huidigeDatum = new Date(datum_van + "T00:00:00");
        const stopDatum = new Date(eindDatum + "T00:00:00");
        while (huidigeDatum <= stopDatum) {
            const d = huidigeDatum.getFullYear() + "-" + String(huidigeDatum.getMonth() + 1).padStart(2, "0") + "-" + String(huidigeDatum.getDate()).padStart(2, "0");
            const data = new FormData();
            data.append("action", "gbh_blokkade_opslaan");
            data.append("gbh_nonce", gbhNonce);
            data.append("datum", d);
            data.append("hele_dag", hele_dag);
            data.append("tijd_van", tijd_van);
            data.append("tijd_tot", tijd_tot);
            taken.push(fetch(ajaxUrl, { method: "POST", body: data }));
            huidigeDatum.setDate(huidigeDatum.getDate() + 1);
        }
        Promise.all(taken.map(p => p.then(r => r.json()))).then(results => {
            const fouten = results.filter(r => !r.success);
            if (fouten.length > 0) {
                msg.style.color = "#c62828";
                msg.textContent = "Sommige datums konden niet worden opgeslagen.";
                return;
            }
            msg.style.color = "#2e7d32";
            msg.textContent = "Blokkade(s) opgeslagen!";
            document.getElementById("gbh-blok-datum").value = "";
            document.getElementById("gbh-blok-datum-tot").value = "";
            document.getElementById("gbh-blok-van").value = "";
            document.getElementById("gbh-blok-tot").value = "";
            document.getElementById("gbh-blok-heledag").checked = false;
            document.getElementById("gbh-blok-tijden").style.display = "flex";
            document.getElementById("gbh-blok-datum-tot-wrap").style.display = "none";

            // Zorg dat er altijd een tabel is
            const lijstDiv = document.getElementById("gbh-blokkades-lijst");
            if (!lijstDiv.querySelector("tbody")) {
                lijstDiv.innerHTML = "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\"><thead><tr style=\"background:#fdecea;\"><th style=\"padding:8px;text-align:left;\">Datum</th><th style=\"padding:8px;text-align:left;\">Tijd</th><th style=\"padding:8px;\"></th></tr></thead><tbody></tbody></table>";
            }
            const tbl = lijstDiv.querySelector("tbody");
            let huidigeDatum2 = new Date(datum_van);
            const stopDatum2 = new Date(eindDatum);
            let i = 0;
            while (huidigeDatum2 <= stopDatum2) {
                const d = huidigeDatum2.toISOString().split("T")[0];
                const parts = d.split("-");
                const datumNl = parts[2] + "-" + parts[1] + "-" + parts[0];
                const tijdStr = hele_dag ? "Hele dag" : tijd_van + " - " + tijd_tot;
                const nieuweId = results[i] && results[i].data ? results[i].data.id : null;
                const tr = document.createElement("tr");
                tr.style.borderBottom = "1px solid #eee";
                tr.innerHTML = "<td style=\"padding:8px;\">" + datumNl + "</td><td style=\"padding:8px;\">" + tijdStr + "</td><td style=\"padding:8px;text-align:right;\"><button type=\"button\" style=\"padding:4px 12px;border:0;border-radius:6px;background:#c62828;color:#fff;cursor:pointer;font-size:13px;\">Verwijderen</button></td>";
                koppelVerwijderKnop(tr.querySelector("button"), tr, nieuweId);
                tbl.appendChild(tr);
                huidigeDatum2.setDate(huidigeDatum2.getDate() + 1);
                i++;
            }
        });
    });
   function koppelVerwijderKnop(btn, tr, nieuweId) {
        btn.addEventListener("click", function() {
            if (!confirm("Blokkade verwijderen?")) return;
            const id = nieuweId || btn.dataset.id;
            const data = new FormData();
            data.append("action", "gbh_blokkade_verwijderen");
            data.append("gbh_nonce", gbhNonce);
            data.append("id", id);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    tr.remove();
                    const msg = document.getElementById("gbh-blok-msg");
                    msg.style.color = "#2e7d32";
                    msg.textContent = "Blokkade verwijderd.";
                }
            });
        });
    }

    document.querySelectorAll(".gbh-blok-del").forEach(function(btn) {
        koppelVerwijderKnop(btn, btn.closest("tr"), null);
    });

    document.getElementById("gbh-logout-btn").addEventListener("click", function() {
        const data = new FormData();
        data.append("action", "gbh_logout");
        data.append("gbh_nonce", gbhNonce);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
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
        data.append("gbh_nonce", gbhNonce);
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

document.querySelectorAll(".gbh-annuleer-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const id = btn.dataset.id;
            const form = document.getElementById("gbh-edit-" + id);
            form.style.display = "none";
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
            data.append("gbh_nonce", gbhNonce);
            data.append("id", id);
            data.append("naam", naam);
            data.append("email", email);
            data.append("telefoon", telefoon);
            fetch(ajaxUrl, { method: "POST", body: data })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); });
        });
    });

document.querySelectorAll(".gbh-afspraak-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.getElementById("gbh-nieuw-afspraak-klant-id").value = btn.dataset.id;
            document.getElementById("gbh-nieuw-afspraak-naam").value = btn.dataset.naam;
            document.getElementById("gbh-nieuw-afspraak-email").value = btn.dataset.email;
            document.getElementById("gbh-nieuw-afspraak-telefoon").value = btn.dataset.telefoon;
            document.getElementById("gbh-nieuw-afspraak-datum").value = "";
            document.getElementById("gbh-nieuw-afspraak-tijd").value = "";
            document.getElementById("gbh-nieuw-afspraak-msg").textContent = "";
            document.querySelectorAll(".gbh-nieuw-behandeling").forEach(function(cb) { cb.checked = false; });
            document.getElementById("gbh-nieuw-afspraak-popup").style.display = "block";
            document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "block";
        });
    });
    var sluitBtn = document.getElementById("gbh-nieuw-afspraak-sluiten");
    if (sluitBtn) sluitBtn.addEventListener("click", function() {
        document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
        document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
    });

    var overlayNieuw = document.getElementById("gbh-nieuw-afspraak-overlay");
    if (overlayNieuw) overlayNieuw.addEventListener("click", function() {
        document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
        document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
    });

    var opslaanBtn = document.getElementById("gbh-nieuw-afspraak-opslaan");
    if (opslaanBtn) opslaanBtn.addEventListener("click", function() {
        const naam = document.getElementById("gbh-nieuw-afspraak-naam").value.trim();
        const email = document.getElementById("gbh-nieuw-afspraak-email").value.trim();
        const telefoon = document.getElementById("gbh-nieuw-afspraak-telefoon").value.trim();
        const datum = document.getElementById("gbh-nieuw-afspraak-datum").value;
        const tijd = document.getElementById("gbh-nieuw-afspraak-tijd").value;
        const msg = document.getElementById("gbh-nieuw-afspraak-msg");
        const gekozen = [];
        let behandeltijd = 0;
        let prijs = 0;
        document.querySelectorAll(".gbh-nieuw-behandeling").forEach(function(cb) {
            if (cb.checked) {
                gekozen.push(cb.dataset.naam);
                behandeltijd += parseInt(cb.dataset.tijd);
                prijs += parseFloat(cb.dataset.prijs);
            }
        });
        if (!datum || !tijd) { msg.style.color = "#c62828"; msg.textContent = "Vul datum en tijd in."; return; }
        if (gekozen.length === 0) { msg.style.color = "#c62828"; msg.textContent = "Kies minimaal één behandeling."; return; }
        const data = new FormData();
        data.append("action", "gbh_save_booking");
        data.append("gbh_nonce", gbhNonce);
        data.append("naam", naam);
        data.append("email", email);
        data.append("telefoon", telefoon);
        data.append("datum", datum);
        data.append("tijd", tijd);
        data.append("behandelingen", gekozen.join(", "));
        data.append("behandeltijd", behandeltijd);
        data.append("prijs", prijs);
        fetch(ajaxUrl, { method: "POST", body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                msg.style.color = "#2e7d32";
                msg.textContent = "Afspraak gemaakt!";
                setTimeout(function() {
                    document.getElementById("gbh-nieuw-afspraak-popup").style.display = "none";
                    document.getElementById("gbh-nieuw-afspraak-overlay").style.display = "none";
                }, 1000);
            } else {
                msg.style.color = "#c62828";
                msg.textContent = res.data;
            }
        });
    });

    document.querySelectorAll(".gbh-del-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            if (!confirm("Weet je zeker dat je deze klant wilt verwijderen?")) return;
            const data = new FormData();
            data.append("action", "gbh_klant_verwijderen");
            data.append("gbh_nonce", gbhNonce);
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

        // Blokkades toevoegen aan bookings_list
        $blokkades_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gbh_blokkades", ARRAY_A);
        $geblokkeerde_dagen = [];
        foreach ($blokkades_raw as $bl) {
            if ($bl['hele_dag']) {
                $geblokkeerde_dagen[] = $bl['datum'];
            } else {
                $van_ts = strtotime('1970-01-01 ' . substr($bl['tijd_van'], 0, 5));
                $tot_ts = strtotime('1970-01-01 ' . substr($bl['tijd_tot'], 0, 5));
                for ($t = $van_ts; $t < $tot_ts; $t += 15 * 60) {
                    $bookings_list[] = $bl['datum'] . ' ' . date('H:i', $t);
                }
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
        $nonce = wp_create_nonce('gbh_ajax_nonce');

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
@media(max-width:600px) {
    .gbh-summary-box { position:static; width:100%; box-sizing:border-box; box-shadow:none; margin-top:20px; }
}
.gbh-summary-box strong { color:#7d3c98; font-size:16px; }
.gbh-next-btn { display:block; width:100%; margin-top:14px; padding:12px; border:0; border-radius:8px; background:#7d3c98; color:#fff; cursor:pointer; font-size:15px; font-weight:600; }
.gbh-next-btn:hover { background:#6a2f82; }
h3.gbh-cat { color:#7d3c98; font-size:15px; margin:0 0 8px; border-bottom:2px solid #e8d5f5; padding-bottom:6px; }
.gbh-welkom { padding:10px 14px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; color:#2e7d32; font-weight:600; margin-bottom:12px; font-size:15px; display:none; }
@keyframes gbh-knipperen { 0%, 100% { opacity:1; } 50% { opacity:0; } }
@keyframes gbh-knipperen-tijd { 0%, 100% { opacity:1; } 50% { opacity:0; } }
</style>';

        echo '<div class="gbh-booking">';
        echo '<div style="text-align:right;margin-bottom:16px;"><a href="https://goodbyehair.nl/medewerker-login/" style="font-size:13px;color:#999;text-decoration:none;border:1px solid #ddd;border-radius:8px;padding:6px 12px;">Medewerker login</a></div>';
        echo '<h2>Kies je behandeling</h2>';
        echo '<div class="gbh-columns">';

        foreach ($treatments as $category => $items) {
            echo '<div class="gbh-col">';
            echo '<h3 class="gbh-cat">' . esc_html($category) . '</h3>';
            foreach ($items as $t) {
                echo '<label class="gbh-treatment-label">';
                echo '<input type="checkbox" class="gbh-treatment" data-time="' . esc_attr($t['time']) . '" data-price="' . esc_attr($t['price']) . '" data-name="' . esc_attr($t['name']) . '"> ';
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
    const boekingenVastgesteld = ' . json_encode($bookings_list) . ';
    let bookings = boekingenVastgesteld.slice();
    let geblokkeerde_dagen = ' . json_encode($geblokkeerde_dagen) . ';
    const gbhAjaxUrl = "' . $ajax_url . '";
    const gbhCalNonce = "' . $nonce . '";

    window.gbhSetBlokkades = function(dagen, slots) {
        geblokkeerde_dagen = dagen;
        bookings = boekingenVastgesteld.slice();
        slots.forEach(function(slot) {
            if (!bookings.includes(slot)) bookings.push(slot);
        });
    };
    window.gbhRenderCalendar = function() {
        renderCalendar();
    };
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
        volgendeBtn.style.animation = "none";
        const datumHeader = document.getElementById("gbh-datum-header");
        datumHeader.style.background = "#7d3c98";
        datumHeader.style.color = "#fff";
        datumHeader.style.fontSize = "18px";
        datumHeader.style.padding = "12px 20px";
        datumHeader.style.animation = "gbh-knipperen 2s step-start infinite";
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
           const monthValue = String(month + 1).padStart(2, "0");
            const dayValue = String(d).padStart(2, "0");
            const fullDate = year + "-" + monthValue + "-" + dayValue;
            const isGeblokkeerd = geblokkeerde_dagen.includes(fullDate);
            const isEnabled = enabled && !isPast && !isGeblokkeerd;
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
                    for (let t = new Date(startTs); t < endTs; t.setMinutes(t.getMinutes() + 15)) {
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
                        tijdHeader.style.animation = "none";
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
        echo '<label style="display:block;margin-bottom:10px;">Telefoon <span style="color:#c62828;">*</span><br><input type="tel" id="gbh-telefoon" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';

        echo '<button type="submit" id="gbh-bevestig" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;margin-top:10px;">Afspraak bevestigen</button>';
        echo '<button type="button" id="gbh-back-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-top:10px;margin-left:10px;">← Terug</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<script>
document.addEventListener("DOMContentLoaded", function () {
function positionSummary() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        box.style.top = Math.max(20, rect.top + window.pageYOffset + 20) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    }
    positionSummary();
    window.addEventListener("scroll", function() {
        const anchor = document.getElementById("gbh-summary-anchor");
        const box = document.querySelector(".gbh-summary-box");
        if (!anchor || !box) return;
        const rect = anchor.getBoundingClientRect();
        const centeredTop = (window.innerHeight / 2) - (box.offsetHeight / 2);
        box.style.top = Math.max(20, centeredTop) + "px";
        box.style.left = (rect.left + window.pageXOffset) + "px";
    });
    window.addEventListener("resize", positionSummary);
    const checkboxes = document.querySelectorAll(".gbh-treatment");
    const totalTime = document.getElementById("gbh-total-time");
    const totalPrice = document.getElementById("gbh-total-price");
    const nextButton = document.getElementById("gbh-next-step");
    const step1 = document.querySelector(".gbh-booking");
    const step2 = document.getElementById("gbh-step-2");
    const step3 = document.getElementById("gbh-step-3");
    const ajaxUrl = "' . $ajax_url . '";
    const gbhNonce = "' . $nonce . '";
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
            const blokData = new FormData();
            blokData.append("action", "gbh_get_blokkades");
            blokData.append("gbh_nonce", gbhNonce);
            fetch(ajaxUrl, { method: "POST", body: blokData })
            .then(r => r.json())
            .then(function(res) {
                if (res.success && window.gbhSetBlokkades) {
                    window.gbhSetBlokkades(res.data.geblokkeerde_dagen, res.data.geblokkeerde_slots);
                }
                step1.style.display = "none";
                step2.style.display = "block";
                if (window.gbhRenderCalendar) window.gbhRenderCalendar();
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
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

    // Klantherkenning via localStorage
    const emailInput = document.getElementById("gbh-email");
    const telefoonInput = document.getElementById("gbh-telefoon");
    if (telefoonInput) {
        telefoonInput.addEventListener("input", function() {
            telefoonInput.setCustomValidity("");
        });
    }
    const gbhOpgeslaanNaam     = localStorage.getItem("gbh_naam");
    const gbhOpgeslaanEmail    = localStorage.getItem("gbh_email");
    const gbhOpgeslaanTelefoon = localStorage.getItem("gbh_telefoon");
    if (gbhOpgeslaanNaam && gbhOpgeslaanEmail) {
        document.getElementById("gbh-naam").value     = gbhOpgeslaanNaam;
        document.getElementById("gbh-email").value    = gbhOpgeslaanEmail;
        document.getElementById("gbh-telefoon").value = gbhOpgeslaanTelefoon;
        const welkom = document.getElementById("gbh-welkom");
        welkom.style.display = "block";
        welkom.textContent = "Welkom terug, " + gbhOpgeslaanNaam + "! Je gegevens zijn ingevuld.";
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
                if (cb.checked) behandelingen.push(cb.dataset.name);
            });
            if (!naam || !email || !datum || !tijd) {
                return;
            }
            const telCijfers = telefoon.replace(/[\s\-]/g, "");
            const telInput = document.getElementById("gbh-telefoon");
          if (telCijfers[0] !== "0" || !/^\d{10}$/.test(telCijfers)) {
                telInput.setCustomValidity("Geef een Nederlands telefoonnummer van 10 cijfers, beginnend met 0.");
                telInput.reportValidity();
                return;
            }
            telInput.setCustomValidity("");
            const data = new FormData();
            data.append("action", "gbh_save_booking");
            data.append("gbh_nonce", gbhNonce);
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
                    localStorage.setItem("gbh_naam", naam);
                    localStorage.setItem("gbh_email", email);
                    localStorage.setItem("gbh_telefoon", telefoon);
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

        $boek = $wpdb->get_row($wpdb->prepare("SELECT behandelingen FROM {$wpdb->prefix}gbh_bookings WHERE id = %d", $id));
        $behandelingen_annuleer = $boek ? $boek->behandelingen : '';
        $timestamp = wp_next_scheduled('gbh_stuur_herinnering', [$id, $email, $naam, $datum, $tijd, $behandelingen_annuleer]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'gbh_stuur_herinnering', [$id, $email, $naam, $datum, $tijd, $behandelingen_annuleer]);
        }
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
        if (!check_ajax_referer('gbh_ajax_nonce', 'gbh_nonce', false)) {
            wp_send_json_error('Ongeldige aanvraag.');
        }
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
        $tel_cijfers = preg_replace('/[\s\-]/', '', $telefoon);
        if (!preg_match('/^0\d{9}$/', $tel_cijfers)) {
            wp_send_json_error('Geef een geldig Nederlands telefoonnummer van 10 cijfers, beginnend met 0.');
        }

        $slots_nodig  = ceil($behandeltijd / 15) + 1;
        $nieuwe_start = strtotime('1970-01-01 ' . $tijd);
        $nieuwe_eind  = $nieuwe_start + ($slots_nodig * 15 * 60);
        $bestaande    = $wpdb->get_results($wpdb->prepare(
            "SELECT tijd, behandeltijd FROM $table WHERE datum = %s", $datum
        ));
        $blokkades    = $wpdb->get_results($wpdb->prepare(
            "SELECT hele_dag, tijd_van, tijd_tot FROM {$wpdb->prefix}gbh_blokkades WHERE datum = %s", $datum
        ));
        $bezet = false;
        foreach ($bestaande as $b) {
            $b_start = strtotime('1970-01-01 ' . substr($b->tijd, 0, 5));
            $b_eind  = $b_start + ((ceil($b->behandeltijd / 15) + 1) * 15 * 60);
            if ($nieuwe_start < $b_eind && $nieuwe_eind > $b_start) { $bezet = true; break; }
        }
        foreach ($blokkades as $bl) {
            if ($bl->hele_dag) { $bezet = true; break; }
            $bl_start = strtotime('1970-01-01 ' . substr($bl->tijd_van, 0, 5));
            $bl_eind  = strtotime('1970-01-01 ' . substr($bl->tijd_tot, 0, 5));
            if ($nieuwe_start < $bl_eind && $nieuwe_eind > $bl_start) { $bezet = true; break; }
        }
        if ($bezet) {
            wp_send_json_error('Dit tijdslot is helaas net bezet geraakt. Kies een ander tijdstip.');
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
        $boeking_id = $wpdb->insert_id;
        // E-mail naar klant
        $onderwerp_klant = 'Bevestiging afspraak GoodByeHair';
       $headers_klant = ['Content-Type: text/html; charset=UTF-8'];
        $bericht_klant  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht_klant .= "Beste " . $naam . ",<br><br>";
        $bericht_klant .= "Je afspraak is bevestigd!<br><br>";
        $bericht_klant .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht_klant .= "Tijd: " . $tijd . "<br>";
        $bericht_klant .= "Behandelingen: " . $behandelingen . "<br>";
        $bericht_klant .= "Behandeltijd: " . $behandeltijd . " minuten<br><br>";
        $bericht_klant .= "Tot dan!<br><br>";
        $bericht_klant .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, $onderwerp_klant, $bericht_klant, $headers_klant);

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
        $afspraak_timestamp    = get_gmt_from_date($datum . ' ' . $tijd, 'U');
        $herinnering_timestamp = $afspraak_timestamp - (24 * 60 * 60);
        if ($herinnering_timestamp > time()) {
            wp_schedule_single_event($herinnering_timestamp, 'gbh_stuur_herinnering', [$boeking_id, $email, $naam, $datum, $tijd, $behandelingen]);
        }

        wp_send_json_success('Afspraak opgeslagen.');
    }

public function test_herinnering() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_test_herinnering_nonce');
        $email = get_option('gbh_salon_email', 'info@goodbyehair.nl');
        $this->stuur_herinnering(0, $email, 'Test Klant', date('Y-m-d', strtotime('+1 day')), '10:00', 'Oksels');
        wp_redirect(admin_url('admin.php?page=gbh-settings&test_verstuurd=1'));
        exit;
    }
    
    public function stuur_herinnering($booking_id, $email, $naam, $datum, $tijd, $behandelingen) {
        $onderwerp = 'Herinnering afspraak GoodByeHair';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $bericht  = '<img src="https://goodbyehair.nl/wp-content/uploads/2023/10/goodbyehair-2.png" alt="Goodbyehair" style="max-width:200px;margin-bottom:20px;"><br><br>';
        $bericht .= "Beste " . $naam . ",<br><br>";
        $bericht .= "Dit is een herinnering voor je afspraak van morgen!<br><br>";
        $bericht .= "Datum: " . date('d-m-Y', strtotime($datum)) . "<br>";
        $bericht .= "Tijd: " . $tijd . "<br>";
        $bericht .= "Behandelingen: " . $behandelingen . "<br><br>";
        $bericht .= "Tot morgen!<br><br>";
        $bericht .= "Met vriendelijke groet,<br>Goodbyehair<br>Bergerhof 16<br>6871ZJ Renkum<br>06 22 438 738<br>info@goodbyehair.nl";
        wp_mail($email, $onderwerp, $bericht, $headers);
    }

public function register_settings() {
        register_setting('gbh_settings_group', 'gbh_days');
        register_setting('gbh_settings_group', 'gbh_times');
        register_setting('gbh_settings_group', 'gbh_salon_email');
        register_setting('gbh_settings_group', 'gbh_medewerker_user');
       add_action('admin_post_gbh_sla_wachtwoord_op', [$this, 'sla_wachtwoord_op']);
    add_action('admin_post_gbh_test_herinnering', [$this, 'test_herinnering']);
    }

public function sla_wachtwoord_op() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('gbh_wachtwoord_nonce');
        $nieuw = $_POST['gbh_medewerker_pass_nieuw'] ?? '';
        $user  = sanitize_text_field($_POST['gbh_medewerker_user'] ?? '');
        if ($user) update_option('gbh_medewerker_user', $user);
        if (!empty($nieuw)) {
            update_option('gbh_medewerker_pass', password_hash($nieuw, PASSWORD_DEFAULT));
            
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
        <?php if (isset($_GET['test_verstuurd'])) : ?>
                <div class="notice notice-success"><p>Test herinneringsmail verstuurd!</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gbh_test_herinnering">
                <?php wp_nonce_field('gbh_test_herinnering_nonce'); ?>
                <button type="submit" style="padding:8px 16px;border:0;border-radius:6px;background:#7d3c98;color:#fff;cursor:pointer;">Stuur test herinneringsmail</button>
            </form>
            <br>
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
