<script setup>
import BadgeList from "../Badges/BadgeList.vue";
import SettingsSection from "./SettingsSection.vue";

// The badges a student holds, all of them (Task #103, N10). Home shows the latest few.
defineProps({
    badges: { type: Array, required: true },
});
</script>

<template>
    <SettingsSection
        :title="$t('badges.profile_title')"
        :description="$t('badges.profile_description')"
    >
        <BadgeList v-if="badges.length > 0" :badges="badges" />
        <p v-else class="text-sm text-fg-muted" data-test="no-badges">
            {{ $t("badges.none_yet") }}
        </p>
    </SettingsSection>
</template>
