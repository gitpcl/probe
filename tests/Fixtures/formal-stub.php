#!/usr/bin/env php
<?php

declare(strict_types=1);

$mode = getenv('PROBE_FORMAL_STUB') ?: 'pass';

if ($mode === 'hang') {
    sleep(3);
    fwrite(STDOUT, "No counterexample found.\n");
    exit(0);
}

if ($mode === 'counter') {
    fwrite(STDOUT, "Counterexample found. Instance:\nUser.email = none\n");
    exit(1);
}

if ($mode === 'tla') {
    fwrite(STDOUT, "Error: Invariant Inv is violated.\nState 1: x = 0\n");
    exit(1);
}

if ($mode === 'dafny') {
    fwrite(STDOUT, "Dafny program verifier finished with 1 verified, 2 errors\n");
    exit(1);
}

if ($mode === 'lean') {
    fwrite(STDOUT, "spec.lean:3:0: error: unknown identifier nope\n");
    exit(1);
}

fwrite(STDOUT, "No counterexample found.\n");
exit(0);
