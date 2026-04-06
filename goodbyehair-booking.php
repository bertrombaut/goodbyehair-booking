<?php
/**
 * Plugin Name: GoodByeHair Booking
 */

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'gbh_create_table');

function gbh_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'gbh_bookings';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
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
    dbDelta($sql);
}

class GBH_Booking {

    public function __construct() {
        add_shortcode('gbh_booking', [$this, 'render']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_gbh_save_booking', [$this, 'save_booking']);
        add_action('wp_ajax_nopriv_gbh_save_booking', [$this, 'save_booking']);
        add_action('gbh_stuur_herinnering', [$this, 'stuur_herinnering'], 10, 6);
        add_action('admin_post_gbh_annuleer', [$this, 'annuleer_boeking']);
    }

    public function render() {

        global $wpdb;
        $table = $wpdb->prefix . 'gbh_bookings';
        $booked = $wpdb->get_results("SELECT datum, tijd, behandeltijd FROM $table", ARRAY_A);
        $bookings_list = [];
        foreach ($booked as $b) {
            $start_time = substr($b['tijd'], 0, 5);
            $start_ts = strtotime('1970-01-01 ' . $start_time);
            $duur = intval($b['behandeltijd']);
            $slots = ceil($duur / 15)+1;
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
.gbh-summary-box { padding:16px; border:2px solid #7d3c98; border-radius:12px; background:#faf5ff; }
.gbh-summary-box strong { color:#7d3c98; font-size:16px; }
.gbh-next-btn { display:block; width:100%; margin-top:14px; padding:12px; border:0; border-radius:8px; background:#7d3c98; color:#fff; cursor:pointer; font-size:15px; font-weight:600; }
.gbh-next-btn:hover { background:#6a2f82; }
h3.gbh-cat { color:#7d3c98; font-size:15px; margin:0 0 8px; border-bottom:2px solid #e8d5f5; padding-bottom:6px; }
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
        echo '<div class="gbh-summary-box" id="gbh-summary">';
        echo '<strong>Overzicht</strong><br><br>';
        echo '<div style="font-size:14px;margin-bottom:4px;">Behandeltijd: <span id="gbh-total-time">0</span> min</div>';
        echo '<div style="font-size:14px;">Totaal: <strong>€<span id="gbh-total-price">0,00</span></strong></div>';
        echo '<button type="button" id="gbh-next-step" class="gbh-next-btn">Kies een datum/tijd →</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div id="gbh-step-2" style="display:none;margin-top:20px;">';
        echo '<div id="gbh-datum-header" style="display:inline-block;margin-bottom:12px;padding:12px 20px;background:#7d3c98;color:#fff;border-radius:8px;font-weight:700;font-size:18px;">Kies een datum</div>';
        echo '<div id="gbh-calendar" style="margin-bottom:20px;"></div>';
        echo '<div id="gbh-chosen-date" style="margin:0 0 12px 0;font-weight:600;"></div>';
        echo '<div id="gbh-times-header" style="display:none;margin-bottom:12px;padding:12px 20px;background:#7d3c98;color:#fff;border-radius:8px;font-weight:700;font-size:18px;">Kies een tijdstip</div>';
        echo '<div id="gbh-times"></div>';
        echo '<input type="hidden" id="gbh-selected-date" value="">';
        echo '<input type="hidden" id="gbh-selected-time" value="">';
        echo '<div style="margin-top:16px;">';
        echo '<button type="button" id="gbh-back-to-step1" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-right:10px;">← Terug</button>';
        echo '<button type="button" id="gbh-next-to-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;">Volgende →</button>';
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
        const datumHeader = document.getElementById("gbh-datum-header");
        datumHeader.style.background = "#7d3c98";
        datumHeader.style.color = "#fff";
        datumHeader.style.fontSize = "18px";
        datumHeader.style.padding = "12px 20px";
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
                const dateObj = new Date(selectedDate);
                const dayKey = map[dateObj.getDay()];
                const dayTimes = times[dayKey];
                const timesContainer = document.getElementById("gbh-times");
                const timesHeader = document.getElementById("gbh-times-header");
                const behandeltijd = parseInt(document.getElementById("gbh-total-time").textContent) || 15;
                const slotsNeeded = Math.ceil(behandeltijd / 15);
                document.getElementById("gbh-selected-time").value = "";
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
                        let isBooked = bookings.includes(selectedDate + " " + time);
                        let fitsInDay = (index + slotsNeeded) <= allSlots.length;
                        let blockedByDuration = false;
                        if (fitsInDay) {
                            for (let s = 0; s < slotsNeeded; s++) {
                                const checkSlot = allSlots[index + s];
                                if (checkSlot && bookings.includes(selectedDate + " " + checkSlot)) {
                                    blockedByDuration = true;
                                    break;
                                }
                            }
                        }
                        let isDisabled = isBooked || !fitsInDay || blockedByDuration;
                        html += "<button type=\"button\" class=\"gbh-time\" data-time=\"" + time + "\" " + (isDisabled ? "disabled" : "") + " style=\"margin:0 8px 8px 0;padding:10px 14px;border:1px solid " + (isDisabled ? "#ddd" : "#ccc") + ";border-radius:8px;background:" + (isDisabled ? "#eee" : "#fff") + ";cursor:" + (isDisabled ? "not-allowed" : "pointer") + ";\">" + time + "</button>";
                    });
                }
                timesContainer.innerHTML = html;
                timesHeader.style.display = "inline-block";
                document.getElementById("gbh-datum-header").style.background = "#e8d5f5";
                document.getElementById("gbh-datum-header").style.color = "#7d3c98";
                document.getElementById("gbh-datum-header").style.fontSize = "14px";
                document.getElementById("gbh-datum-header").style.padding = "6px 12px";
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
            document.getElementById("gbh-step-2").style.display = "none";
            document.querySelector(".gbh-booking").style.display = "block";
        });
    }

    renderCalendar();
});
</script>';

        echo '</div>';
        echo '<div id="gbh-chosen-time" style="margin-top:12px;font-weight:600;"></div>';
        echo '<div id="gbh-step-3" style="display:none;margin-top:20px;">';
        echo '<h2>Jouw gegevens</h2>';
        echo '<div id="gbh-step3-summary" style="margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:10px;max-width:400px;"></div>';
        echo '<label style="display:block;margin-bottom:10px;">Naam<br><input type="text" id="gbh-naam" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
        echo '<label style="display:block;margin-bottom:10px;">E-mail<br><input type="email" id="gbh-email" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
        echo '<label style="display:block;margin-bottom:10px;">Telefoon<br><input type="tel" id="gbh-telefoon" style="width:100%;max-width:400px;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:4px;"></label>';
        echo '<button type="button" id="gbh-bevestig" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;margin-top:10px;">Afspraak bevestigen</button>';
        echo '<button type="button" id="gbh-back-step3" style="padding:10px 18px;border:0;border-radius:8px;background:#ccc;color:#000;cursor:pointer;margin-top:10px;margin-left:10px;">← Terug</button>';
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
                alert("Kies eerst een datum en tijd.");
                return;
            }
            const summary = document.getElementById("gbh-step3-summary");
            summary.innerHTML = "Datum: <strong>" + date + "</strong><br>Tijd: <strong>" + time + "</strong><br>Behandeltijd: <strong>" + totalTime.textContent + " min</strong><br>Prijs: <strong>€" + totalPrice.textContent + "</strong>";
            step2.style.display = "none";
            step3.style.display = "block";
        });
    }

    const backStep3Button = document.getElementById("gbh-back-step3");
    if (backStep3Button) {
        backStep3Button.addEventListener("click", function () {
            step3.style.display = "none";
            step2.style.display = "block";
        });
    }

    const bevestigButton = document.getElementById("gbh-bevestig");
    if (bevestigButton) {
        bevestigButton.addEventListener("click", function () {
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
                alert("Vul alle verplichte velden in.");
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
            fetch("' . esc_url(admin_url('admin-ajax.php')) . '", {
                method: "POST",
                body: data
            })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (response.success) {
                    step3.innerHTML = "<div style=\"padding:20px;border:1px solid #ccc;border-radius:10px;max-width:400px;\"><h2>Afspraak bevestigd!</h2><p>Bedankt " + naam + ", je afspraak op " + datum + " om " + tijd + " is vastgelegd.</p></div>";
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
            'Instellingen',
            'Instellingen',
            'manage_options',
            'gbh-settings',
            [$this, 'settings_page']
        );
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
        $table = $wpdb->prefix . 'gbh_bookings';
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
        $wpdb->insert($table, [
            'naam'          => $naam,
            'email'         => $email,
            'telefoon'      => $telefoon,
            'datum'         => $datum,
            'tijd'          => $tijd,
            'behandelingen' => $behandelingen,
            'behandeltijd'  => $behandeltijd,
            'prijs'         => $prijs,
        ]);

        $onderwerp_klant = 'Bevestiging afspraak GoodByeHair';
        $bericht_klant  = "Beste " . $naam . ",\n\n";
        $bericht_klant .= "Je afspraak is bevestigd!\n\n";
        $bericht_klant .= "Datum: " . $datum . "\n";
        $bericht_klant .= "Tijd: " . $tijd . "\n";
        $bericht_klant .= "Behandelingen: " . $behandelingen . "\n";
        $bericht_klant .= "Behandeltijd: " . $behandeltijd . " minuten\n";
        $bericht_klant .= "Prijs: €" . number_format($prijs, 2, ',', '.') . "\n\n";
        $bericht_klant .= "Tot dan!\n";
        $bericht_klant .= "GoodByeHair";
        wp_mail($email, $onderwerp_klant, $bericht_klant);

        $salon_email = get_option('gbh_salon_email', '');
        if ($salon_email) {
            $onderwerp_salon = 'Nieuwe afspraak: ' . $naam;
            $bericht_salon  = "Er is een nieuwe afspraak gemaakt!\n\n";
            $bericht_salon .= "Naam: " . $naam . "\n";
            $bericht_salon .= "Email: " . $email . "\n";
            $bericht_salon .= "Telefoon: " . $telefoon . "\n";
            $bericht_salon .= "Datum: " . $datum . "\n";
            $bericht_salon .= "Tijd: " . $tijd . "\n";
            $bericht_salon .= "Behandelingen: " . $behandelingen . "\n";
            $bericht_salon .= "Behandeltijd: " . $behandeltijd . " minuten\n";
            $bericht_salon .= "Prijs: €" . number_format($prijs, 2, ',', '.') . "\n";
            wp_mail($salon_email, $onderwerp_salon, $bericht_salon);
        }

        $afspraak_timestamp = strtotime($datum . ' ' . $tijd);
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
        $bericht .= "Datum: " . $datum . "\n";
        $bericht .= "Tijd: " . $tijd . "\n";
        $bericht .= "Behandelingen: " . $behandelingen . "\n\n";
        $bericht .= "Tot morgen!\n";
        $bericht .= "GoodByeHair";
        wp_mail($email, $onderwerp, $bericht);
    }

    public function register_settings() {
        register_setting('gbh_settings_group', 'gbh_days');
        register_setting('gbh_settings_group', 'gbh_times');
        register_setting('gbh_settings_group', 'gbh_salon_email');
    }

    public function settings_page() {
        $times = get_option('gbh_times', []);
        $days = get_option('gbh_days', []);
        $salon_email = get_option('gbh_salon_email', '');
        ?>
        <div class="wrap">
            <h1>Booking instellingen</h1>
            <form method="post" action="options.php">
                <?php settings_fields('gbh_settings_group'); ?>

                <h3>E-mail salon</h3>
                <label>E-mailadres voor nieuwe boekingen:<br>
                    <input type="email" name="gbh_salon_email" value="<?php echo esc_attr($salon_email); ?>" style="width:300px;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;">
                </label>
                <br><br>

                <h3>Werkdagen</h3>
                <?php
                $all_days = ['ma','di','wo','do','vr','za','zo'];
                foreach ($all_days as $day) {
                    ?>
                    <label>
                        <input type="checkbox" name="gbh_days[]" value="<?php echo $day; ?>" <?php checked(in_array($day, $days)); ?>>
                        <?php echo strtoupper($day); ?>
                    </label><br>
                    <?php
                }
                ?>
                <h3>Tijden per dag</h3>
                <?php
                foreach ($all_days as $day) {
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
                    <?php
                }
                ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new GBH_Booking();
