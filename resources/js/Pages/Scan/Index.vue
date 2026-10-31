<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    AlertCircle,
    CheckCircle2,
    Camera,
    QrCode,
    RotateCw,
} from "@lucide/vue";
import { BrowserQRCodeReader } from "@zxing/library";
import Btn from "../../Components/Topics/Btn.vue";
import { extractCode } from "../../qrCode.js";
import { categoryDot, categoryLabel } from "../../categoryColors.js";

// Student: scan an event's QR code to confirm attendance (UC-2.3, Task #51).
// The server decides everything (POST /qr/redeem, Task #45): this page only
// reads the code and shows the answer.
defineOptions({ layout: { title: "Scan QR code" } });

const props = defineProps({
    // Set when the code was opened from the phone's own camera app.
    code: { type: String, default: null },
});

const videoRef = ref(null);
const cameraError = ref(null);
const cameraActive = ref(false);
const processing = ref(false);
const result = ref(null); // { type: 'success'|'progress'|'error', title, message }
const pending = ref(props.code); // a code waiting for the student to confirm
let reader = null;
let lastText = "";
let lastTime = 0;

const showCamera = computed(() => !pending.value && !result.value);

function csrf() {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content") || ""
    );
}

async function redeem(text) {
    const code = extractCode(text);
    if (!code || processing.value) return;

    processing.value = true;
    stopCamera();

    try {
        const response = await fetch("/qr/redeem", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrf(),
            },
            body: JSON.stringify({ code }),
        });
        const data = await response.json().catch(() => ({}));

        pending.value = null;

        if (response.ok) {
            result.value =
                data.outcome === "credited"
                    ? {
                          type: "success",
                          title: `${data.points} points credited`,
                          message: data.event,
                          points: data.points,
                          category: data.category,
                      }
                    : {
                          type: "progress",
                          title: `Part ${data.step} of ${data.total_steps} recorded`,
                          message: `${data.message} (${data.event})`,
                      };
        } else {
            result.value = {
                type: "error",
                title: "Could not confirm attendance",
                message:
                    response.status === 429
                        ? "Too many attempts. Wait a minute and try again."
                        : data.message ||
                          data.errors?.code?.[0] ||
                          "Something went wrong. Try again.",
            };
        }
    } catch {
        pending.value = null;
        result.value = {
            type: "error",
            title: "Connection error",
            message: "The server could not be reached. Try again.",
        };
    } finally {
        processing.value = false;
    }
}

async function startCamera() {
    cameraError.value = null;

    try {
        reader ??= new BrowserQRCodeReader();
        const devices = await reader.listVideoInputDevices();

        if (devices.length === 0) {
            cameraError.value =
                "No camera was found on this device. Sign in on another device to scan.";
            return;
        }

        // Prefer the rear camera on a phone.
        const rear = devices.find((d) =>
            /back|rear|environment/i.test(d.label),
        );

        cameraActive.value = true;
        await reader.decodeFromVideoDevice(
            (rear ?? devices[0]).deviceId,
            videoRef.value,
            (found) => {
                if (!found) return;

                // The camera sees the same code many times a second.
                const text = found.getText();
                const now = Date.now();
                if (text === lastText && now - lastTime < 3000) return;
                lastText = text;
                lastTime = now;

                redeem(text);
            },
        );
    } catch {
        cameraActive.value = false;
        cameraError.value =
            "The camera could not be used. Allow camera access in your browser (it needs a secure https page), or sign in on another device.";
    }
}

function stopCamera() {
    reader?.reset();
    cameraActive.value = false;
}

function scanAnother() {
    result.value = null;
    pending.value = null;
    lastText = "";
    // The video element is shown again on the next tick.
    setTimeout(startCamera, 0);
}

onMounted(() => {
    if (!props.code) startCamera();
});

onUnmounted(stopCamera);
</script>

<template>
    <div class="mx-auto max-w-md space-y-5">
        <!-- A link opened from the phone's camera: confirm before claiming -->
        <section
            v-if="pending"
            class="space-y-4 rounded-card border border-line bg-surface p-6 text-center shadow-card"
        >
            <QrCode class="mx-auto size-10 text-primary" />
            <div>
                <h2 class="text-base font-semibold text-fg">
                    Confirm your attendance
                </h2>
                <p class="mt-1 text-sm text-fg-muted">
                    You opened an event QR code. Claim your points now?
                </p>
            </div>
            <Btn
                variant="primary"
                :loading="processing"
                data-test="claim"
                @click="redeem(pending)"
            >
                Claim points
            </Btn>
        </section>

        <!-- Answer from the server -->
        <section
            v-else-if="result"
            class="space-y-4 rounded-card border border-line bg-surface p-6 text-center shadow-card"
            :class="{ 'confirm-in': result.type === 'success' }"
            role="status"
            data-test="result"
        >
            <span
                class="mx-auto flex size-12 items-center justify-center rounded-full"
                :class="
                    result.type === 'error'
                        ? 'bg-danger-soft text-danger-fg'
                        : result.type === 'progress'
                          ? 'bg-warning-soft text-warning-fg'
                          : 'bg-success-soft text-success-fg'
                "
            >
                <AlertCircle v-if="result.type === 'error'" class="size-6" />
                <CheckCircle2 v-else class="size-6" />
            </span>
            <div>
                <h2 class="text-base font-semibold text-fg">
                    {{ result.title }}
                </h2>
                <p class="mt-1 text-sm text-fg-muted">{{ result.message }}</p>
            </div>
            <!-- What was credited: the points and where they count -->
            <dl
                v-if="result.points"
                class="mx-auto grid max-w-xs grid-cols-2 gap-3 rounded-control bg-surface-muted p-3 text-sm"
                data-test="confirmation"
            >
                <div>
                    <dt class="text-fg-muted">Points</dt>
                    <dd
                        class="text-lg font-semibold text-fg"
                        data-test="points"
                    >
                        +{{ result.points }}
                    </dd>
                </div>
                <div>
                    <dt class="text-fg-muted">Category</dt>
                    <dd
                        class="flex items-center justify-center gap-1.5 text-lg font-semibold text-fg"
                        data-test="category"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :class="categoryDot[result.category]"
                            aria-hidden="true"
                        />
                        {{ categoryLabel[result.category] ?? result.category }}
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap justify-center gap-2">
                <Btn variant="primary" data-test="again" @click="scanAnother">
                    <RotateCw class="size-4" />
                    Scan another code
                </Btn>
                <Link
                    href="/my-events"
                    class="inline-flex items-center justify-center rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                >
                    My events
                </Link>
            </div>
        </section>

        <!-- Camera -->
        <template v-if="showCamera">
            <div
                class="relative flex min-h-[320px] items-center justify-center overflow-hidden rounded-card border border-line bg-black"
            >
                <video
                    ref="videoRef"
                    class="max-h-[460px] w-full object-cover"
                    muted
                    playsinline
                ></video>

                <div
                    v-if="cameraActive && !cameraError"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                >
                    <div
                        class="size-56 rounded-2xl border-2 border-primary/70 shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]"
                    ></div>
                </div>

                <div
                    v-if="cameraError"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center space-y-3 bg-surface/95 p-6 text-center"
                    data-test="camera-error"
                >
                    <Camera class="size-8 text-fg-muted" />
                    <p class="text-sm text-fg-muted">{{ cameraError }}</p>
                    <Btn @click="startCamera">Try the camera again</Btn>
                </div>

                <div
                    v-if="processing"
                    class="absolute inset-0 z-20 flex items-center justify-center gap-2 bg-black/50 font-medium text-white"
                >
                    <RotateCw class="size-5 animate-spin" />
                    Checking the code…
                </div>
            </div>

            <p class="text-center text-sm text-fg-muted">
                Point the camera at the QR code shown at the event.
            </p>
        </template>
    </div>
</template>
