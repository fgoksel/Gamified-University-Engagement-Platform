# Demo over https (Task #88)

Browsers give the camera only to pages opened over **https** (or `localhost`). A phone that opens the
laptop's address, such as `http://192.168.0.20:8081`, is plain http, so the QR scanner shows no camera.
This page gives every demo an https address and a few checks to run before the demo.

Nothing here changes the app. Normal work stays on http://localhost:8081.

## What you need

- Docker Desktop with the project running (`docker compose up -d`)
- a phone with internet access (mobile data or any Wi-Fi; it does **not** have to be the laptop's network)
- the laptop with internet access

## Steps

1. **Use built assets, not the Vite dev server.** While `public/hot` exists the page asks the phone for
   `localhost:5173`, which is the phone itself, so the page stays blank.

   ```powershell
   docker compose stop vite
   Remove-Item public\hot -ErrorAction SilentlyContinue
   docker compose run --rm vite npm run build
   ```

2. **Start the tunnel and read the address.**

   ```powershell
   docker compose --profile demo up -d tunnel
   docker compose logs tunnel | Select-String trycloudflare.com
   ```

   The log shows an address like `https://random-words.trycloudflare.com`. It is free, needs no account and
   **changes every time the tunnel starts**.

3. **Open that address on the phone** and sign in with a demo account (see `docs/sprint2-demo.md`).
   The scanner asks for camera access: allow it.

4. **Check it works:** on the phone open *Scan QR code*. The camera view opens. Scan a QR code shown on the
   laptop, for example an event poster from *QR codes*.

## After the demo

```powershell
docker compose --profile demo stop tunnel
docker compose up -d vite     # back to the dev server for normal work
```

If you built the assets for the demo, `npm run build` output stays in `public/build`; it is ignored by git,
and the dev server takes over again as soon as `public/hot` is back.

## How it works

The tunnel service (`cloudflared`) ends the TLS connection and forwards the request to the local web
server with `X-Forwarded-Proto: https`. `docker/nginx/default.conf` passes that on to PHP as `HTTPS=on`,
only for that request, so every link, redirect and asset is built with https and the session works as
usual. Requests to http://localhost:8081 are unchanged.

## If something is wrong

| Sign | Cause | Fix |
|---|---|---|
| Blank page on the phone | `public/hot` exists, so assets point to `localhost:5173` | Step 1 |
| "The camera only works on a secure page" or no camera | The phone opened an `http://` address | Use the address from step 2 |
| Camera blocked | Access was refused once | Allow the camera for the site in the browser's settings, then reload |
| Tunnel address not shown | Container still starting | Wait 10 seconds and run the `logs` command again |
| Tunnel never connects | Faculty or guest Wi-Fi blocks outgoing connections | Use mobile data on the laptop (hotspot) or another network |
