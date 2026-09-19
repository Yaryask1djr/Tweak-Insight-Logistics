# Authentication and authorization

The current application uses signed, short-lived JWT bearer tokens. The API
validates each token, reloads the user from the database, verifies that the
stored role still matches the token claim, checks the account's current state,
then enforces a named backend permission. React route guards are usability
only; they are not a security boundary.

## Role matrix

| Capability | Client | Driver | Admin |
| --- | :---: | :---: | :---: |
| Create and view own delivery requests | Yes | No | No* |
| View and accept available driver offers | No | Yes | No |
| Update only own assigned delivery status/location | No | Yes | No |
| Confirm proof for only own assigned delivery | No | Yes | No |
| View own earnings | No | Yes | No |
| View and manage only own notification inbox | Yes | Yes | Yes |
| Review, broadcast, cancel, complete deliveries | No | No | Yes |
| Manage driver approval | No | No | Yes |
| View client and operations records | No | No | Yes |

\* An admin must use a dedicated "create on behalf of client" operation when
that workflow is introduced; an admin token cannot create a delivery under its
own user ID.

Resource ownership is enforced inside the controllers as well. A driver status
or GPS update includes `WHERE delivery_person_id = authenticated_user_id`; a
driver cannot update another driver's job even if a request body is modified.
The first acceptance uses an atomic update requiring an unassigned,
`broadcasted` delivery.

## Sanctum and Spatie Permission

Laravel Sanctum and Spatie Permission are Laravel packages. This repository is
not a Laravel application—it is custom PHP with `firebase/php-jwt`—so installing
those packages here would not add usable protection. `authorization_policy.php`
provides the equivalent current backend boundary for the three fixed roles.

If literal Sanctum + Spatie support is required, it must be a planned Laravel
migration: create a Laravel API, migrate the current users/deliveries schema,
replace JWT issuance with Sanctum personal-access tokens, and use Spatie's
database-backed role/permission middleware. Do not run both token systems in
production.
