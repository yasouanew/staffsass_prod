# Mobile API Base URL — Development vs Production

> Scope: what base URL the React Native (Expo) employee app must be configured with
> for each environment, and why. Source of truth is the backend in this repo —
> no API is invented.

## 1. The path suffix (never changes)

Versioned API prefix is declared in [`bootstrap/app.php`](bootstrap/app.php:16)
(`apiPrefix: 'api'`) and the `v1` group is opened in
[`routes/api.php`](routes/api.php:59):

```
/api/v1
```

So the **full** base URL is always `<host>` + `/api/v1`. Every mobile call in
[`docs/mobile/mobile-screen-specification.md`](docs/mobile/mobile-screen-specification.md:17)
is relative to that prefix (`/api/v1/auth/login`, `/api/v1/shifts`, …).

Auth is a Sanctum **bearer token** (not a cookie), so the host does *not* need to
match `APP_URL` — only reachability matters:

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

## 2. Development (local machine = the server)

The Laravel app is served by `php artisan serve`, which by default binds
**loopback only**. That is the critical constraint:

| Where the app runs | Base URL to use |
|---|---|
| Emulator / simulator **on the same machine** | `http://127.0.0.1:8000/api/v1` |
| Android **emulator** (AVD) | `http://10.0.2.2:8000/api/v1` |
| iOS **Simulator** | `http://127.0.0.1:8000/api/v1` |
| **Physical device** on the same Wi-Fi | `http://192.168.1.179:8000/api/v1` |
| Physical device via USB (Android, `adb reverse`) | `http://127.0.0.1:8000/api/v1` |

Notes:

- `127.0.0.1` from inside an Android emulator refers to the **emulator itself**,
  so it will never reach the host. `10.0.2.2` is the emulator's alias for the
  host machine.
- `192.168.1.179` is this machine's current LAN IPv4 (verified via `ipconfig`).
  It changes when the network changes (DHCP), so re-detect it rather than
  hardcoding it in the mobile app.
- The LAN address only works if Laravel is **bound to all interfaces**. Use the
  helper script that already exists for this:
  [`serve-lan.ps1`](serve-lan.ps1:54) → `npm run serve:host`
  (`php artisan serve --host=0.0.0.0 --port=8000`, see
  [`package.json`](package.json:9)). The parent script also rewrites `APP_URL`
  and `FRONTEND_URL` in [`.env`](.env:5) to the LAN IP, which matters for
  invitation / password-reset links rendered server-side
  ([`config/app.php`](config/app.php:29)).
- Windows Firewall must allow inbound TCP 8000, or the phone will time out.
- Plain HTTP is fine in development, but both Android (`usesCleartextTraffic`)
  and iOS ATS must permit cleartext for the LAN IP — otherwise the request is
  blocked before it leaves the device.
- Emulators use `10.0.2.2` — the **LAN** IP usually works there too, but the
  emulator's NAT alias is the reliable choice.

Recommended shape in the mobile app (single source of truth, no hardcoding):

```ts
// Expo reads EXPO_PUBLIC_* from the environment at build time.
const API_ORIGIN =
  process.env.EXPO_PUBLIC_API_ORIGIN ?? 'http://192.168.1.179:8000';

export const API_BASE_URL = `${API_ORIGIN}/api/v1`;
```

## 3. Production

Production uses a real hostname over HTTPS, with the same `/api/v1` suffix:

```
https://api.<your-domain>/api/v1
```

or, if the API shares the web host:

```
https://<your-domain>/api/v1
```

- HTTPS is mandatory (bearer tokens on the wire). No `http://`, no port suffix
  when behind a load balancer / reverse proxy.
- Serving the same paths as the React web app is safe: the web client only ever
  uses relative `VITE_API_BASE_URL` (default `/api/v1`, see
  [`resources/js/lib/api-client.ts`](resources/js/lib/api-client.ts:22)), so
  pointing a subdomain at the same Laravel app changes nothing for the web app.
- Set `APP_URL` (and `FRONTEND_URL`) to the public HTTPS origin so server-generated
  links in emails/webhooks are correct.
- Stripe webhooks ([`routes/api.php`](routes/api.php:59) group /
  `POST /webhooks/stripe/billing`) must be reachable **publicly without auth** —
  do not put the whole domain behind an allow-list that excludes Stripe.
- The production base URL should be compiled into the release build
  (`EXPO_PUBLIC_API_ORIGIN=https://api.example.com`), not switched at runtime.

## 4. Summary

| Environment | Base URL |
|---|---|
| Web app (same-origin) | `/api/v1` (relative) |
| Dev — emulator/simulator on host | `http://127.0.0.1:8000/api/v1` |
| Dev — Android emulator | `http://10.0.2.2:8000/api/v1` |
| Dev — physical device on LAN | `http://192.168.1.179:8000/api/v1` (use `npm run serve:host`) |
| Production | `https://api.<domain>/api/v1` |

Version note: the prefix is versioned at the path level only. There is no
`Accept: application/vnd...` content negotiation, so changing the version means a
new path (`/api/v2`), never a header.
