// Small JSON helper for the Topics screens (lazy tree, search, previews) and
// file uploads that answer with JSON (Task #85). Sends the session cookie and
// Laravel's CSRF token, and turns error responses into a thrown Error with a
// readable message.

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
}

export class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

async function request(method, url, body) {
    // A form with a file goes as it is: the browser sets its content type.
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-XSRF-TOKEN": xsrfToken(),
            ...(body && !isForm ? { "Content-Type": "application/json" } : {}),
        },
        body: isForm ? body : body ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors = data.errors ?? {};
        const first = Object.values(errors).flat()[0];
        throw new ApiError(
            first ??
                data.message ??
                "Something went wrong. Nothing was changed.",
            response.status,
            errors,
        );
    }

    return data;
}

export const api = {
    get: (url) => request("GET", url),
    post: (url, body = {}) => request("POST", url, body),
    upload: (url, form) => request("POST", url, form),
};

// Wait before running a search while someone is still typing.
export function debounce(callback, wait = 250) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => callback(...args), wait);
    };
}
