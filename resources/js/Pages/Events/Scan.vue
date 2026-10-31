<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Camera,
    CheckCircle2,
    RotateCw,
} from "@lucide/vue";
import { BrowserQRCodeReader } from "@zxing/library";
import Btn from "../../Components/Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";

// Organizer: QR Passport scanner & point crediting (UC-1.7, Task #43)
defineOptions({ layout: { title: "Attendance scanner" } });

const props = defineProps({
    event: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const videoRef = ref(null);
const videoDevices = ref([]);
const selectedDeviceId = ref(null);
const cameraActive = ref(false);
const cameraError = ref(null);
const processing = ref(false);

const feedback = ref(null); // { type: 'success'|'warning'|'error', title: '', message: '' }
const creditedCount = ref(props.stats.credited_count || 0);

let codeReader = null;
let lastScannedRaw = "";
let lastScannedTime = 0;

const acceptedCount = computed(() => props.stats.accepted_count || 0);
const progressPercent = computed(() => {
    if (acceptedCount.value === 0) return 0;
    return Math.min(
        100,
        Math.round((creditedCount.value / acceptedCount.value) * 100),
    );
});

function playSuccessBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = "sine";
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
        osc.start();
        osc.stop(ctx.currentTime + 0.18);
    } catch {
        // Audio not allowed or unavailable
    }
}

function playErrorBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = "sawtooth";
        osc.frequency.setValueAtTime(220, ctx.currentTime);
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
        osc.start();
        osc.stop(ctx.currentTime + 0.25);
    } catch {}
}

async function initCamera() {
    cameraError.value = null;
    try {
        if (!codeReader) {
            codeReader = new BrowserQRCodeReader();
        }

        const devices = await codeReader.listVideoInputDevices();
        videoDevices.value = devices;

        if (devices.length === 0) {
            cameraError.value = "No camera devices detected on this device.";
            return;
        }

        // Prefer rear/environment camera on mobile
        if (!selectedDeviceId.value) {
            const backCamera = devices.find(
                (d) =>
                    d.label.toLowerCase().includes("back") ||
                    d.label.toLowerCase().includes("rear") ||
                    d.label.toLowerCase().includes("environment"),
            );
            selectedDeviceId.value = backCamera
                ? backCamera.deviceId
                : devices[0].deviceId;
        }

        await startScanning();
    } catch (err) {
        cameraError.value =
            "Camera access required to scan QR Passports. Please enable camera permissions in browser settings.";
        cameraActive.value = false;
    }
}

async function startScanning() {
    if (!codeReader || !videoRef.value || !selectedDeviceId.value) return;

    try {
        cameraActive.value = true;
        await codeReader.decodeFromVideoDevice(
            selectedDeviceId.value,
            videoRef.value,
            (result, err) => {
                if (result) {
                    onScanResult(result.getText());
                }
            },
        );
    } catch (err) {
        cameraError.value =
            "Camera access required to scan QR Passports. Please enable camera permissions in browser settings.";
        cameraActive.value = false;
    }
}

function stopCamera() {
    if (codeReader) {
        codeReader.reset();
        cameraActive.value = false;
    }
}

async function switchCamera(deviceId) {
    selectedDeviceId.value = deviceId;
    stopCamera();
    await startScanning();
}

function onScanResult(rawText) {
    if (!rawText) return;

    const now = Date.now();
    // Prevent duplicate rapid-fire scans within 2.5 seconds
    if (rawText === lastScannedRaw && now - lastScannedTime < 2500) {
        return;
    }

    lastScannedRaw = rawText;
    lastScannedTime = now;

    submitCredit(rawText);
}

async function submitCredit(identifier) {
    if (processing.value) return;
    processing.value = true;

    try {
        const response = await fetch(`/events/${props.event.id}/credit`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN":
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content") || "",
            },
            body: JSON.stringify({ identifier }),
        });

        const data = await response.json();

        if (data.status === "credited") {
            playSuccessBeep();
            feedback.value = {
                type: "success",
                title: "Attendance Credited!",
                message: data.message,
                student: data.student,
            };

            if (data.credited_count !== undefined) {
                creditedCount.value = data.credited_count;
            } else {
                creditedCount.value++;
            }
        } else if (data.status === "already_credited") {
            playErrorBeep();
            feedback.value = {
                type: "warning",
                title: "Already Credited",
                message: data.message,
                student: data.student,
            };
        } else if (data.status === "not_accepted") {
            playErrorBeep();
            feedback.value = {
                type: "error",
                title: "Not Registered or Selected",
                message: data.message,
                student: data.student,
            };
        } else if (data.status === "self_credit") {
            playErrorBeep();
            feedback.value = {
                type: "error",
                title: "Self-Crediting Prohibited",
                message: data.message,
                student: data.student,
            };
        } else {
            playErrorBeep();
            feedback.value = {
                type: "error",
                title: "Scan Failed",
                message:
                    data.message || "Could not process this passport scan.",
                student: data.student,
            };
        }
    } catch (err) {
        feedback.value = {
            type: "error",
            title: "Connection Error",
            message: "Failed to communicate with the server. Please try again.",
        };
    } finally {
        processing.value = false;
    }
}

onMounted(initCamera);

onUnmounted(() => {
    stopCamera();
});
</script>

<template>
    <div class="space-y-5 max-w-4xl mx-auto">
        <!-- Top Back Link -->
        <div class="flex items-center justify-between">
            <Link
                href="/my-events"
                class="inline-flex min-h-11 items-center gap-1.5 text-sm text-fg-muted hover:text-fg transition focus-visible:outline-2 focus-visible:outline-ring rounded-control"
            >
                <ArrowLeft class="size-4" /> Back to my events
            </Link>

            <Link
                :href="`/events/${event.id}/applicants`"
                class="inline-flex min-h-11 items-center gap-1.5 text-sm text-fg-muted hover:text-fg transition focus-visible:outline-2 focus-visible:outline-ring rounded-control"
            >
                View applicants list
            </Link>
        </div>

        <!-- Header Summary Card -->
        <div
            class="rounded-card border border-line bg-surface p-4 sm:p-5 shadow-sm space-y-4"
        >
            <div
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span
                            class="size-2.5 rounded-full"
                            :class="categoryDot[event.category] || 'bg-primary'"
                        ></span>
                        <h1 class="text-xl font-bold text-fg">
                            {{ event.title }}
                        </h1>
                    </div>
                    <p class="text-xs sm:text-sm text-fg-muted mt-0.5">
                        Attendance Verification &bull; {{ event.points_value }}
                        points per student
                    </p>
                </div>

                <!-- Stats Badge & Progress -->
                <div class="flex items-center gap-4 text-sm">
                    <div class="text-right">
                        <div class="font-semibold text-fg">
                            {{ creditedCount }} / {{ acceptedCount }}
                        </div>
                        <div class="text-xs text-fg-muted">
                            Students Credited ({{ progressPercent }}%)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div
                class="w-full bg-surface-muted rounded-full h-2 overflow-hidden"
            >
                <div
                    class="bg-primary h-2 rounded-full transition-all duration-300"
                    :style="{ width: `${progressPercent}%` }"
                ></div>
            </div>
        </div>

        <!-- Live Scan Feedback Banner -->
        <div
            v-if="feedback"
            class="rounded-card border p-4 transition duration-200"
            :class="{
                'bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-100':
                    feedback.type === 'success',
                'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-100':
                    feedback.type === 'warning',
                'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-100':
                    feedback.type === 'error',
            }"
            data-test="feedback-banner"
        >
            <div class="flex items-start gap-3">
                <CheckCircle2
                    v-if="feedback.type === 'success'"
                    class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400 mt-0.5"
                />
                <AlertTriangle
                    v-else-if="feedback.type === 'warning'"
                    class="size-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5"
                />
                <AlertCircle
                    v-else
                    class="size-5 shrink-0 text-rose-600 dark:text-rose-400 mt-0.5"
                />

                <div class="flex-1">
                    <h3 class="font-semibold text-sm">{{ feedback.title }}</h3>
                    <p class="text-xs sm:text-sm mt-0.5 opacity-90">
                        {{ feedback.message }}
                    </p>
                </div>

                <button
                    type="button"
                    class="text-xs opacity-60 hover:opacity-100 transition p-1"
                    aria-label="Dismiss feedback"
                    @click="feedback = null"
                >
                    &times;
                </button>
            </div>
        </div>

        <!-- CAMERA SCANNER VIEW -->
        <div class="space-y-4">
            <!-- Camera Viewfinder Box -->
            <div
                class="relative rounded-card border border-line bg-black overflow-hidden flex flex-col items-center justify-center min-h-[360px] sm:min-h-[440px]"
            >
                <video
                    ref="videoRef"
                    class="w-full h-full object-cover max-h-[500px]"
                    muted
                    playsinline
                ></video>

                <!-- Viewfinder Target Reticle Overlay -->
                <div
                    v-if="cameraActive && !cameraError"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                >
                    <div
                        class="relative w-64 h-64 border-2 border-primary/70 rounded-2xl shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]"
                    >
                        <!-- Corners -->
                        <div
                            class="absolute top-0 left-0 size-6 border-t-4 border-l-4 border-primary rounded-tl-lg"
                        ></div>
                        <div
                            class="absolute top-0 right-0 size-6 border-t-4 border-r-4 border-primary rounded-tr-lg"
                        ></div>
                        <div
                            class="absolute bottom-0 left-0 size-6 border-b-4 border-l-4 border-primary rounded-bl-lg"
                        ></div>
                        <div
                            class="absolute bottom-0 right-0 size-6 border-b-4 border-r-4 border-primary rounded-br-lg"
                        ></div>

                        <!-- Scanner Line Animation -->
                        <div
                            class="w-full h-0.5 bg-primary/90 absolute top-1/2 -translate-y-1/2 animate-pulse"
                        ></div>
                    </div>
                </div>

                <!-- Camera Access Error / Permission State -->
                <div
                    v-if="cameraError"
                    class="absolute inset-0 bg-surface/95 flex flex-col items-center justify-center p-6 text-center space-y-3 z-10"
                    data-test="camera-error"
                >
                    <div
                        class="size-12 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center"
                    >
                        <Camera class="size-6" />
                    </div>
                    <h3 class="font-semibold text-fg">Camera Unavailable</h3>
                    <p class="text-xs sm:text-sm text-fg-muted max-w-md">
                        {{ cameraError }}
                    </p>
                    <div class="flex gap-2 pt-2">
                        <Btn variant="primary" @click="initCamera">
                            Retry Camera
                        </Btn>
                    </div>
                </div>

                <!-- Processing Overlay -->
                <div
                    v-if="processing"
                    class="absolute inset-0 bg-black/40 flex items-center justify-center text-white gap-2 font-medium z-20"
                >
                    <RotateCw class="size-5 animate-spin" />
                    <span>Verifying Passport...</span>
                </div>
            </div>

            <!-- Camera Controls & Switcher -->
            <div
                v-if="videoDevices.length > 1"
                class="flex items-center justify-between text-xs text-fg-muted px-1"
            >
                <label for="camera-select" class="font-medium"
                    >Active Camera:</label
                >
                <select
                    id="camera-select"
                    v-model="selectedDeviceId"
                    class="min-h-11 rounded-control border border-line bg-surface px-2.5 py-1 text-xs"
                    @change="switchCamera(selectedDeviceId)"
                >
                    <option
                        v-for="device in videoDevices"
                        :key="device.deviceId"
                        :value="device.deviceId"
                    >
                        {{
                            device.label ||
                            `Camera ${device.deviceId.slice(0, 5)}`
                        }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>