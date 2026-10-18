<?php

namespace App\Interpolate6;

final class Counter
{
    public function increment(int $i): string
    {
        # `.` reads the CV $i after $i++ ran: "2-1" instead of "1-1".
        return $i . $this->show($i++) . '!';
    }

    public function assigned(string $s): string
    {
        return $s . $this->show($s = 'x') . '!';
    }

    public function byReference(string $s): string
    {
        return $s . '-' . $this->change($s) . '!';
    }

    public function referenced(string $s): string
    {
        $ref = &$s;

        return $s . '-' . $this->change($ref) . '!';
    }

    private function show(int|string $v): string
    {
        return '-' . $v;
    }

    private function change(string &$s): string
    {
        $s = 'changed';

        return 'x';
    }
}

?>
