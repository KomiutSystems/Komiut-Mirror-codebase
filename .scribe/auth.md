# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_SANCTUM_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a bearer token from the <code>POST api/login</code> or <code>POST api/register</code> endpoints. All requests must also send the <code>X-App-Key</code> brand header.
