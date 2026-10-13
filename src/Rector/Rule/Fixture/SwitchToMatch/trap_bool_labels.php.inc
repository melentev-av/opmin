<?php

namespace App\SwitchMatchTrap19;

function label(int $i): string
{
    switch (true) {
        case $i > 10:
            return 'big';
        case $i > 0:
            return 'small';
        default:
            return 'none';
    }
}

?>
