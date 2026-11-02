# Security

Probe executes arbitrary PHP inside the application it is installed in, and several tools shell out to local binaries. It is a local development dependency.

- Install it with `composer require gitpcl/probe --dev` so `--no-dev` installs never carry it.
- Never expose the worker socket beyond the local machine.
- Never point it at snippets you did not write.

The README's Security section lists what each tool touches.

## Reporting a vulnerability

Report privately. Use GitHub's private vulnerability reporting on this repository (Security tab, "Report a vulnerability") or email hello@pedroclopes.com. Do not open a public issue. You will get an acknowledgement, and a fix is coordinated with you before anything is published.

## Supported versions

Only the latest release receives security fixes.
