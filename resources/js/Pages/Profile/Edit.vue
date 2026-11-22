<script setup>
import AppearanceSection from "../../Components/Profile/AppearanceSection.vue";
import BadgesSection from "../../Components/Profile/BadgesSection.vue";
import PasswordSection from "../../Components/Profile/PasswordSection.vue";
import ProfileDataSection from "../../Components/Profile/ProfileDataSection.vue";
import { t } from "../../i18n.js";

// Profile Settings of teachers and students
defineOptions({ layout: { title: t("profile.title") } });

defineProps({
    profile: { type: Object, required: true },
    // The badges a student holds (Task #103); null for everyone who cannot earn any
    badges: { type: Array, default: null },
});
</script>

<template>
    <div class="space-y-6">
        <ProfileDataSection :profile="profile" />
        <!-- "See all" on Home links here -->
        <div v-if="badges !== null" id="badges">
            <BadgesSection :badges="badges" />
        </div>
        <PasswordSection />
        <AppearanceSection />
    </div>
</template>
