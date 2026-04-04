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
        echo '</div>';

        echo '</div>';

        echo '<script>
        document.addEventListener("DOMContentLoaded", function () {
            const checkboxes = document.querySelectorAll(".gbh-treatment");
            const totalTime = document.getElementById("gbh-total-time");
            const totalPrice = document.getElementById("gbh-total-price");

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

            updateTotals();
        });
        </script>';

        return ob_get_clean();
    }
}

new GBH_Booking();
