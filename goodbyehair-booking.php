<?php
/**
 * Plugin Name: GoodByeHair Booking
 */

if (!defined('ABSPATH')) exit;

class GBH_Booking {

    public function __construct() {
        add_shortcode('gbh_booking', [$this, 'render']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function render() {

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

        echo '<div class="gbh-booking">';
        echo '<h2>Kies je behandeling</h2>';

        foreach ($treatments as $category => $items) {
            echo '<h3>' . esc_html($category) . '</h3>';

            foreach ($items as $t) {
                echo '<label style="display:block;margin-bottom:6px;">';
                echo '<input type="checkbox" class="gbh-treatment" data-time="' . esc_attr($t['time']) . '" data-price="' . esc_attr($t['price']) . '"> ';
                echo esc_html($t['name']) . ' - ' . esc_html($t['time']) . ' min - €' . esc_html($t['price']);
                echo '</label>';
            }
        }

        echo '<div id="gbh-summary" style="margin-top:20px;padding:14px;border:1px solid #ddd;border-radius:10px;max-width:320px;">';
        echo '<strong>Overzicht</strong><br>';
        echo 'Totale behandeltijd: <span id="gbh-total-time">0</span> min<br>';
        echo 'Totale prijs: €<span id="gbh-total-price">0,00</span>';
        echo '<div style="margin-top:14px;">';
        echo '<button type="button" id="gbh-next-step" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;">Volgende</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div id="gbh-step-2" style="display:none;margin-top:20px;">';
       $days = get_option('gbh_days', []);
        echo '<div id="gbh-times"></div>';
$today = date('N');

if (!in_array(['ma','di','wo','do','vr','za','zo'][$today-1], $days)) {
    echo '<div style="margin-bottom:12px;color:#c62828;font-weight:600;">Vandaag geen beschikbaarheid</div>';
}
     echo '<div id="gbh-calendar" style="margin-bottom:20px;"></div>';
    echo '<div id="gbh-chosen-date" style="margin:0 0 12px 0;font-weight:600;">Gekozen datum: geen</div>';
    echo '<input type="hidden" id="gbh-selected-date" value="">';
    echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const calendar = document.getElementById("gbh-calendar");
    if (!calendar) return;

    const days = ' . json_encode(get_option('gbh_days', [])) . ';
    const monthNames = ["Januari","Februari","Maart","April","Mei","Juni","Juli","Augustus","September","Oktober","November","December"];
    const dayNames = ["Ma","Di","Wo","Do","Vr","Za","Zo"];
    const map = ["zo","ma","di","wo","do","vr","za"];

    let current = new Date();
    let year = current.getFullYear();
    let month = current.getMonth();
    let selectedDate = "";

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

        for (let i = 0; i < firstDay; i++) {
            html += "<div></div>";
        }

        for (let d = 1; d <= totalDays; d++) {
            const date = new Date(year, month, d);
            const dayKey = map[date.getDay()];
            const enabled = days.includes(dayKey);
            const monthValue = String(month + 1).padStart(2, "0");
            const dayValue = String(d).padStart(2, "0");
            const fullDate = year + "-" + monthValue + "-" + dayValue;
            const isSelected = selectedDate === fullDate;

            html += "<button type=\"button\" class=\"gbh-calendar-day\" data-date=\"" + fullDate + "\" " + (enabled ? "" : "disabled") + " style=\"padding:10px;border:1px solid " + (isSelected ? "#7d3c98" : "#ccc") + ";border-radius:6px;text-align:center;cursor:" + (enabled ? "pointer" : "not-allowed") + ";background:" + (enabled ? (isSelected ? "#7d3c98" : "#fff") : "#eee") + ";color:" + (isSelected ? "#fff" : "#000") + ";\">" + d + "</button>";
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
                renderCalendar();
            });
        });

        document.getElementById("gbh-prev-month").addEventListener("click", function () {
            month--;
            if (month < 0) {
                month = 11;
                year--;
            }
            renderCalendar();
        });

        document.getElementById("gbh-next-month").addEventListener("click", function () {
            month++;
            if (month > 11) {
                month = 0;
                year++;
            }
            renderCalendar();
        });
    }

    renderCalendar();
});
</script>';

        $times = get_option('gbh_times', []);

        $start_ts = strtotime($start);
        $end_ts = strtotime($end);

        for ($t = $start_ts; $t <= $end_ts; $t += 900) {
            $time = date('H:i', $t);
            echo '<button type="button" class="gbh-time" data-time="' . esc_attr($time) . '" style="margin:0 8px 8px 0;padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">' . esc_html($time) . '</button>';
        }

        echo '</div>';
        echo '<div id="gbh-chosen-time" style="margin-top:12px;font-weight:600;">Gekozen tijd: geen</div>';
        echo '<input type="hidden" id="gbh-selected-time" value="">';
        echo '<div style="margin-top:20px;">';
        echo '</div>';

        echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const checkboxes = document.querySelectorAll(".gbh-treatment");
    const totalTime = document.getElementById("gbh-total-time");
    const totalPrice = document.getElementById("gbh-total-price");
    const nextButton = document.getElementById("gbh-next-step");
    const backButton = document.getElementById("gbh-back-step");
    const step1 = document.querySelector(".gbh-booking");
    const step2 = document.getElementById("gbh-step-2");
    const timeButtons = document.querySelectorAll(".gbh-time");
    const chosenTimeText = document.getElementById("gbh-chosen-time");
    const selectedTimeInput = document.getElementById("gbh-selected-time");

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

    if (nextButton && step1 && step2) {
        nextButton.addEventListener("click", function () {
            let hasSelection = false;

            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) {
                    hasSelection = true;
                }
            });

            if (!hasSelection) {
                const summaryBox = document.getElementById("gbh-summary");

                if (summaryBox && !document.getElementById("gbh-error")) {
                    summaryBox.insertAdjacentHTML("beforeend", "<div id=\"gbh-error\" style=\"margin-top:12px;color:#c62828;font-weight:600;\">Kies eerst minimaal één behandeling</div>");
                }
                return;
            }

            const oldError = document.getElementById("gbh-error");
            if (oldError) {
                oldError.remove();
            }

            step1.style.display = "none";
            step2.style.display = "block";
        });
    }

    if (backButton && step1 && step2) {
        backButton.addEventListener("click", function () {
            step2.style.display = "none";
            step1.style.display = "block";
        });
    }

    if (timeButtons.length && chosenTimeText && selectedTimeInput) {
        timeButtons.forEach(function (button) {
            button.addEventListener("click", function () {
                timeButtons.forEach(function (btn) {
                    btn.style.background = "#fff";
                    btn.style.borderColor = "#ccc";
                    btn.style.color = "#000";
                });

                button.style.background = "#7d3c98";
                button.style.borderColor = "#7d3c98";
                button.style.color = "#fff";

                const chosenTime = button.dataset.time;
                selectedTimeInput.value = chosenTime;
                chosenTimeText.textContent = "Gekozen tijd: " + chosenTime;
            });
        });
    }

    updateTotals();
});
</script>';

        return ob_get_clean();
    }

    public function admin_menu() {
        add_options_page(
            'Booking instellingen',
            'Booking',
            'manage_options',
            'gbh-booking',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('gbh_settings_group', 'gbh_days');
        register_setting('gbh_settings_group', 'gbh_times');
    }

    public function settings_page() {
        $times = get_option('gbh_times', []);
        $days = get_option('gbh_days', []);
       $times = get_option('gbh_times', []);
        ?>
        <div class="wrap">
            <h1>Booking instellingen</h1>
            <form method="post" action="options.php">
                <?php settings_fields('gbh_settings_group'); ?>

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
$all_days = ['ma','di','wo','do','vr','za','zo'];
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
