// What a student's scan or paste may contain: the whole link stored in the QR
// image (".../qr/redeem/<token>.<signature>") or just "<token>.<signature>".
// The server checks the signature, so this only finds the code in the text.
export function extractCode(text) {
    const raw = String(text ?? "").trim();
    const match = raw.match(/\/qr\/redeem\/([A-Za-z0-9.]+)/);

    return match ? match[1] : raw;
}
