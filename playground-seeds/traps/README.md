# traps scenario

Code from the verifier trap fixtures plus project tests that **deliberately do not catch** the
breaking rewrites: every test here passes both on the original code and on a wrong "optimization".
The differential tester must reject those rewrites anyway — that is what this scenario checks.

The trap code itself is `../code/Traps.php` and `../code/MagicBag.php`, copied into the scenario.
