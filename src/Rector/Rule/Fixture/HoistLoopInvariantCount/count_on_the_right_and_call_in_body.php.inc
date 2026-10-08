<?php

namespace App\Count2;

final class Printer
{
    public function print(array $lines, string $n): void
    {
        for ($i = 0; \count($lines) > $i; ++$i) {
            $this->line($lines[$i] . $n);
        }
    }

    private function line(string $s): void
    {
        echo $s;
    }
}

?>
-----
<?php

namespace App\Count2;

final class Printer
{
    public function print(array $lines, string $n): void
    {
        for ($i = 0, $n2 = \count($lines); $n2 > $i; ++$i) {
            $this->line($lines[$i] . $n);
        }
    }

    private function line(string $s): void
    {
        echo $s;
    }
}

?>
