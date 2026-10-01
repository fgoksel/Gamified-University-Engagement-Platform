<script setup>
import { QrCode } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// Student: scan an event's QR code to confirm attendance (UC-2.3)
defineOptions({ layout: { title: "Scan QR code" } });
</script>

<template>
    <EmptyState :icon="QrCode" title="Scanner coming soon">
        Point your camera at the QR code shown at the event to confirm your
        attendance.
    </EmptyState>
</template>
