# Module operations — review and approval

`php bin/console module:plan Widget enable` previews a module state change without writing a state file. Browser operations are two-step: Admin → Modules → Review activation/deactivation → type confirmation → apply. The state fingerprint incorporates all relevant module names, versions, declared dependencies, required flags and the currently stored activation states; it is recalculated **under the state-file lock**. A stale or malformed fingerprint rejects the change.

`ModuleManager::planChange($name, true|false)` is nonmutating. `ModuleManager::setEnabled($name, $target, $expectedToken)` optionally enforces the preview fingerprint atomically; legacy two-argument CLI calls are preserved. This is an API-compatible optional argument, not a change to Module API `0.2.0`.

Notes: The approval token is a concurrency guard, not an authentication token; authorization and CSRF validation remain controller requirements. Installing new module PHP is reserved for the signed CLI package installer. The approval flow does not apply/reverse migrations or restart FPM/queue workers. Additional modules and data access must be security-reviewed independently.
