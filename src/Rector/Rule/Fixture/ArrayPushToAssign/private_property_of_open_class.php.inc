<?php

namespace App\ArrayPush4;

class Queue
{
    private array $jobs = [];

    public function push(string $job): void
    {
        if ($job !== '') {
            \array_push($this->jobs, $job);
        }
    }
}

?>
-----
<?php

namespace App\ArrayPush4;

class Queue
{
    private array $jobs = [];

    public function push(string $job): void
    {
        if ($job !== '') {
            $this->jobs[] = $job;
        }
    }
}

?>
