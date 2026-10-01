<script setup>
import AppearanceSection from "../../Components/Profile/AppearanceSection.vue";
import PasswordSection from "../../Components/Profile/PasswordSection.vue";
import ProfileDataSection from "../../Components/Profile/ProfileDataSection.vue";

// Profile Settings of teachers and students
defineOptions({ layout: { title: "Profile settings" } });

defineProps({
    profile: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-6">
        <ProfileDataSection :profile="profile" />
        <PasswordSection />
        <AppearanceSection />
    </div>
</template>
