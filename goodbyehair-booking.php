<?php
/**
 * Plugin Name: GoodByeHair Booking
 */

if (!defined('ABSPATH')) exit;

class GBH_Booking {

    public function __construct() {
        add_shortcode('gbh_booking', [$this, 'render']);
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
        echo '<h2>Kies je behandeling test</h2>';

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
       echo '<h3>Kies datum en tijd</h3>';
       echo '<div id="gbh-times">';
        echo '<button type="button" class="gbh-time" data-time="10:00" style="margin:0 8px 8px 0;padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">10:00</button>';
        echo '<button type="button" class="gbh-time" data-time="10:15" style="margin:0 8px 8px 0;padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">10:15</button>';
        echo '<button type="button" class="gbh-time" data-time="10:30" style="margin:0 8px 8px 0;padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">10:30</button>';
        echo '<button type="button" class="gbh-time" data-time="10:45" style="margin:0 8px 8px 0;padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#fff;cursor:pointer;">10:45</button>';
        echo '</div>';
        echo '<div id="gbh-chosen-time" style="margin-top:12px;font-weight:600;">Gekozen tijd: geen</div>';
        echo '<div style="margin-top:20px;">';
        echo '<button type="button" id="gbh-back-step" style="padding:10px 18px;border:0;border-radius:8px;background:#7d3c98;color:#fff;cursor:pointer;">← Terug</button>';
        echo '</div>';
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
document.title = "";
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
}

new GBH_Booking();
