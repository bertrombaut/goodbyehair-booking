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

        echo '<h2>Kies je behandeling TeST op disndag 31 maart om 12:42 uur</h2>';

        foreach ($treatments as $category => $items) {

            echo '<h3>' . $category . '</h3>';

            foreach ($items as $t) {

                echo '<label style="display:block;margin-bottom:6px;">';
                echo '<input type="checkbox"> ';
                echo $t['name'] . ' - ' . $t['time'] . ' min - €' . $t['price'];
                echo '</label>';
            }
        }

        return ob_get_clean();
    }
}

new GBH_Booking();
