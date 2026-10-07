<script setup>
import { computed, ref, useId, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { Search, UserPlus } from "@lucide/vue";

// "Add a person": pick a role this user may give here, search accounts of
// the right type and add one. The backend checks every tree rule again.
const props = defineProps({
    unitId: { type: Number, required: true },
    roles: { type: Array, required: true },
    tutorPermissions: { type: Array, required: true },
});

const id = useId();
const role = ref(props.roles[0]?.value ?? "");
const query = ref("");
const results = ref([]);
const searching = ref(false);
const searchError = ref("");

const form = useForm({ user_id: null, role: "", permissions: [] });

const isTutor = computed(() => role.value === "tutor");
const formError = computed(
    () => form.errors.user_id || form.errors.role || form.errors.permissions,
);

let timer = null;
let latest = 0;

// Search 300 ms after the user stops typing
watch([query, role], () => {
    clearTimeout(timer);
    searchError.value = "";
    if (query.value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(search, 300);
});

async function search() {
    const request = ++latest;
    searching.value = true;
    const params = new URLSearchParams({
        role: role.value,
        q: query.value.trim(),
    });

    try {
        const response = await fetch(
            `/my-courses/${props.unitId}/candidates?${params}`,
            { headers: { Accept: "application/json" } },
        );
        if (!response.ok) throw new Error();
        const people = await response.json();
        // Ignore answers to older searches
        if (request === latest) results.value = people;
    } catch {
        if (request === latest)
            searchError.value = "The search did not work. Try again.";
    } finally {
        if (request === latest) searching.value = false;
    }
}

function add(person) {
    form.user_id = person.id;
    form.role = role.value;
    form.permissions = isTutor.value ? form.permissions : [];
    form.post(`/my-courses/${props.unitId}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            query.value = "";
            results.value = [];
            form.reset("user_id", "permissions");
        },
    });
}
</script>

<template>
    <section
        class="rounded-card border border-line bg-surface p-5 shadow-card"
        :aria-labelledby="`${id}-heading`"
    >
        <h2
            :id="`${id}-heading`"
            class="flex items-center gap-2 text-base font-semibold text-fg"
        >
            <UserPlus class="size-5" :stroke-width="1.75" />
            Add a person
        </h2>

        <div class="mt-4 space-y-4">
            <div>
                <label
                    :for="`${id}-role`"
                    class="block text-sm font-medium text-fg"
                >
                    Role
                </label>
                <select
                    :id="`${id}-role`"
                    v-model="role"
                    class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-ring"
                >
                    <option
                        v-for="option in roles"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <fieldset v-if="isTutor">
                <legend class="text-sm font-medium text-fg">
                    The tutor may
                </legend>
                <div class="mt-1.5 space-y-1">
                    <label
                        v-for="permission in tutorPermissions"
                        :key="permission.value"
                        class="flex items-center gap-2 text-sm text-fg"
                    >
                        <input
                            v-model="form.permissions"
                            type="checkbox"
                            :value="permission.value"
                            class="size-4 rounded border-line"
                        />
                        {{ permission.label }}
                    </label>
                </div>
            </fieldset>

            <label class="relative block">
                <span class="block text-sm font-medium text-fg"
                    >Find a person</span
                >
                <Search
                    class="pointer-events-none absolute bottom-2.5 left-3 size-4 text-fg-muted"
                    :stroke-width="1.75"
                />
                <input
                    v-model="query"
                    type="search"
                    placeholder="Name, email or Neptun code"
                    class="mt-1.5 block w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-fg placeholder:text-fg-muted focus:outline-2 focus:outline-ring"
                    :aria-controls="`${id}-results`"
                />
            </label>

            <p v-if="formError" class="text-sm text-danger-fg" role="alert">
                {{ formError }}
            </p>
            <p v-if="searchError" class="text-sm text-danger-fg" role="alert">
                {{ searchError }}
            </p>

            <ul
                :id="`${id}-results`"
                class="divide-y divide-line"
                aria-live="polite"
            >
                <li
                    v-for="person in results"
                    :key="person.id"
                    class="flex items-center gap-3 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-fg">
                            {{ person.name }}
                        </p>
                        <p class="truncate text-xs text-fg-muted">
                            {{ person.email }}
                            <template v-if="person.neptunCode">
                                · {{ person.neptunCode }}
                            </template>
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
                        @click="add(person)"
                    >
                        Add
                    </button>
                </li>
            </ul>

            <p
                v-if="
                    query.trim().length >= 2 &&
                    !searching &&
                    results.length === 0 &&
                    !searchError
                "
                class="text-sm text-fg-muted"
                role="status"
            >
                Nobody found who can be added with this role.
            </p>
        </div>
    </section>
</template>
