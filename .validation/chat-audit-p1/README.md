# Chat audit P1 isolated validation

Source repository: `franz-9527/workers`
Source candidate commit: `a552e700213c69c143b7c507d24d4ded10c11247`

Only the source fragments and synthetic contracts needed for P1 are included. No production configuration, user data, database dump, tokens, passwords, or provider credentials are copied.

Checks: PHP 8.3.36 syntax; Node 22 syntax; transport-vs-identity epoch contract; O(1) credential path; separate collaboration discovery; database chat release switch for paid SMS; secret-free normal rendering; explicit server-side reveal; re-authentication/no-store/audit/auto-clear controls.
