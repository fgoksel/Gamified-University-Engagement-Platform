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
import { t } from "../../i18n.js";

// Student: scan an event's QR code to confirm attendance (UC-2.3, Task #51).
// The server decides everything (POST /qr/redeem, Task #45): this page only
// reads the code and shows the answer.
defineOptions({ layout: { title: t("scan.scan_qr_code") } });

const props = defineProps({
    // Set when the code was opened from the phone's own camera app.
    code: { type: String, default: null },
});

const videoRef = ref(null);
const cameraError = ref(null);
const cameraActive = ref(false);
const processing = ref(false);
const result = ref(null); // { type: 'success'|'error', title, message }
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
            result.value = {
                type: "success",
                title: t("scan.points_credited", { points: data.points }),
                message: data.event,
                points: data.points,
                category: data.category,
            };
        } else {
            result.value = {
                type: "error",
                title: t("scan.could_not_confirm_attendance"),
                message:
                    response.status === 429
                        ? t("scan.too_many_attempts_wait")
                        : data.message ||
                          data.errors?.code?.[0] ||
                          t("scan.something_went_wrong_try_again"),
            };
        }
    } catch {
        pending.value = null;
        result.value = {
            type: "error",
            title: t("scan.connection_error"),
            message: t("scan.the_server_could"),
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
            cameraError.value = t("scan.no_camera_was_found");
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
        cameraError.value = t("scan.the_camera_could");
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
                    {{ $t("scan.confirm_your_attendance") }}
                </h2>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("scan.you_opened_an_event_qr") }}
                </p>
            </div>
            <Btn
                variant="primary"
                :loading="processing"
                data-test="claim"
                @click="redeem(pending)"
            >
                {{ $t("scan.claim_points") }}
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
                    <dt class="text-fg-muted">{{ $t("scan.points") }}</dt>
                    <dd
                        class="text-lg font-semibold text-fg"
                        data-test="points"
                    >
                        +{{ result.points }}
                    </dd>
                </div>
                <div>
                    <dt class="text-fg-muted">{{ $t("scan.category") }}</dt>
                    <dd
                        class="flex items-center justify-center gap-1.5 text-lg font-semibold text-fg"
                        data-test="category"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :class="categoryDot[result.category]"
                            aria-hidden="true"
                        />
                        {{ categoryLabel[result.category] }}
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap justify-center gap-2">
                <Btn variant="primary" data-test="again" @click="scanAnother">
                    <RotateCw class="size-4" />
                    {{ $t("scan.scan_another_code") }}
                </Btn>
                <Link
                    href="/my-events"
                    class="inline-flex items-center justify-center rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                >
                    {{ $t("scan.my_events") }}
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
                    <Btn @click="startCamera">{{
                        $t("scan.try_the_camera_again")
                    }}</Btn>
                </div>

                <div
                    v-if="processing"
                    class="absolute inset-0 z-20 flex items-center justify-center gap-2 bg-black/50 font-medium text-white"
                >
                    <RotateCw class="size-5 animate-spin" />
                    {{ $t("scan.checking_the_code") }}
                </div>
            </div>

            <p class="text-center text-sm text-fg-muted">
                {{ $t("scan.point_the_camera") }}
            </p>
        </template>
    </div>
</template>
