<script setup>
import { computed, nextTick, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Archive, Pencil, RotateCcw, Tags } from "@lucide/vue";
import Btn from "../../Components/Topics/Btn.vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import { t, tc } from "../../i18n.js";

// The category list (Task #116, N6): categories may look fixed, but the list changes without a
// code change. For the system admin and the area admin of the University root (CategoryPolicy).
// A rename or a new colour changes how the category shows everywhere; tags, events and points
// keep it. A retired category is offered for nothing new, and everything that has it keeps it.
defineOptions({ layout: { title: t("categories.page.title") } });

const props = defineProps({
    // [{ id, slug, name, color, retired, tags, events }], in the order they were added
    categories: { type: Array, required: true },
    // [{ value, label }]: the colours a category can have (App\Enums\CategoryColor)
    colors: { type: Array, required: true },
    nameMax: { type: Number, required: true },
});

// Written out in full so Tailwind finds them when it scans the code (tokens in app.css)
const swatch = {
    violet: "bg-cat-violet",
    green: "bg-cat-green",
    orange: "bg-cat-orange",
    pink: "bg-cat-pink",
    blue: "bg-cat-blue",
    teal: "bg-cat-teal",
    amber: "bg-cat-amber",
    slate: "bg-cat-slate",
};

const inUse = computed(() => props.categories.filter((c) => !c.retired));
const retired = computed(() => props.categories.filter((c) => c.retired));

// A colour no category in use has yet, so a new one stands out from the rest.
const freeColor = computed(
    () =>
        props.colors.find(
            (color) => !inUse.value.some((c) => c.color === color.value),
        )?.value ?? props.colors[0]?.value,
);

const addForm = useForm({ name: "", color: freeColor.value });

function add() {
    addForm.post("/categories", {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset("name");
            addForm.color = freeColor.value;
        },
    });
}

// One category is edited at a time, in its own row.
const editing = ref(null);
const editForm = useForm({ name: "", color: "" });

async function startEdit(category) {
    editing.value = category.id;
    editForm.clearErrors();
    editForm.name = category.name;
    editForm.color = category.color;
    await nextTick();
    document.getElementById(`category-${category.id}-name`)?.focus();
}

function cancelEdit() {
    const id = editing.value;
    editing.value = null;
    nextTick(() => document.getElementById(`category-${id}-edit`)?.focus());
}

function save(category) {
    editForm.put(`/categories/${category.id}`, {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// Retiring asks once more in the same place, as withdrawing a slip does.
const confirming = ref(null);
const busy = ref(null);
const retireError = ref({});

function retire(category) {
    if (confirming.value !== category.id) {
        confirming.value = category.id;
        return;
    }

    busy.value = category.id;
    retireError.value = {};
    router.post(
        `/categories/${category.id}/retire`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                retireError.value = { [category.id]: errors.category };
            },
            onFinish: () => {
                busy.value = null;
                confirming.value = null;
            },
        },
    );
}

function restore(category) {
    busy.value = category.id;
    router.post(
        `/categories/${category.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

const inputClass = (error) => [
    "mt-1.5 block min-h-11 w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring",
    error ? "border-danger" : "border-line",
];

const usage = (category) =>
    `${tc("categories.page.tags_count", category.tags)} · ${tc("categories.page.events_count", category.events)}`;
</script>

<template>
    <!-- The layout shows the title as the page heading. -->
    <div class="max-w-3xl space-y-8">
        <p class="text-sm text-fg-muted">
            {{ $t("categories.page.intro") }}
        </p>

        <!-- Add a category -->
        <form
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            novalidate
            data-test="add-category"
            @submit.prevent="add"
        >
            <h2 class="text-base font-semibold text-fg">
                {{ $t("categories.page.add_title") }}
            </h2>
            <div>
                <label
                    for="new-category-name"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("categories.page.name") }}
                    <span class="text-danger-fg" aria-hidden="true">*</span>
                </label>
                <input
                    id="new-category-name"
                    v-model="addForm.name"
                    type="text"
                    required
                    :maxlength="nameMax"
                    :aria-invalid="addForm.errors.name ? 'true' : undefined"
                    :aria-describedby="
                        addForm.errors.name
                            ? 'new-category-name-error'
                            : undefined
                    "
                    :class="inputClass(addForm.errors.name)"
                />
                <p
                    v-if="addForm.errors.name"
                    id="new-category-name-error"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ addForm.errors.name }}
                </p>
            </div>
            <fieldset>
                <legend class="text-sm font-medium text-fg">
                    {{ $t("categories.page.color") }}
                </legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label
                        v-for="color in colors"
                        :key="color.value"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border px-3 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-ring"
                        :class="
                            addForm.color === color.value
                                ? 'border-primary bg-primary/10 text-fg'
                                : 'border-line text-fg-muted hover:text-fg'
                        "
                    >
                        <input
                            v-model="addForm.color"
                            type="radio"
                            name="new-category-color"
                            :value="color.value"
                            class="sr-only"
                        />
                        <span
                            class="size-3 rounded-full"
                            :class="swatch[color.value]"
                            aria-hidden="true"
                        />
                        {{ color.label }}
                    </label>
                </div>
                <p
                    v-if="addForm.errors.color"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ addForm.errors.color }}
                </p>
            </fieldset>
            <Btn variant="primary" type="submit" :loading="addForm.processing">
                {{ $t("categories.page.add") }}
            </Btn>
        </form>

        <!-- The categories in use -->
        <section aria-labelledby="categories-in-use" class="space-y-3">
            <h2 id="categories-in-use" class="text-base font-semibold text-fg">
                {{ $t("categories.page.in_use") }}
            </h2>
            <ul
                class="divide-y divide-line rounded-card border border-line bg-surface shadow-card"
                data-test="in-use"
            >
                <li v-for="category in inUse" :key="category.id" class="p-4">
                    <form
                        v-if="editing === category.id"
                        class="space-y-4"
                        novalidate
                        @submit.prevent="save(category)"
                        @keydown.esc.prevent="cancelEdit"
                    >
                        <div>
                            <label
                                :for="`category-${category.id}-name`"
                                class="block text-sm font-medium text-fg"
                            >
                                {{ $t("categories.page.name") }}
                                <span class="text-danger-fg" aria-hidden="true"
                                    >*</span
                                >
                            </label>
                            <input
                                :id="`category-${category.id}-name`"
                                v-model="editForm.name"
                                type="text"
                                required
                                :maxlength="nameMax"
                                :aria-invalid="
                                    editForm.errors.name ? 'true' : undefined
                                "
                                :aria-describedby="
                                    editForm.errors.name
                                        ? `category-${category.id}-name-error`
                                        : undefined
                                "
                                :class="inputClass(editForm.errors.name)"
                            />
                            <p
                                v-if="editForm.errors.name"
                                :id="`category-${category.id}-name-error`"
                                class="mt-1.5 text-sm text-danger-fg"
                            >
                                {{ editForm.errors.name }}
                            </p>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-fg">
                                {{ $t("categories.page.color") }}
                            </legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label
                                    v-for="color in colors"
                                    :key="color.value"
                                    class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border px-3 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-ring"
                                    :class="
                                        editForm.color === color.value
                                            ? 'border-primary bg-primary/10 text-fg'
                                            : 'border-line text-fg-muted hover:text-fg'
                                    "
                                >
                                    <input
                                        v-model="editForm.color"
                                        type="radio"
                                        :name="`category-${category.id}-color`"
                                        :value="color.value"
                                        class="sr-only"
                                    />
                                    <span
                                        class="size-3 rounded-full"
                                        :class="swatch[color.value]"
                                        aria-hidden="true"
                                    />
                                    {{ color.label }}
                                </label>
                            </div>
                            <p
                                v-if="editForm.errors.color"
                                class="mt-1.5 text-sm text-danger-fg"
                            >
                                {{ editForm.errors.color }}
                            </p>
                        </fieldset>
                        <div class="flex flex-wrap gap-2">
                            <Btn
                                variant="primary"
                                type="submit"
                                :loading="editForm.processing"
                            >
                                {{ $t("categories.page.save") }}
                            </Btn>
                            <Btn @click="cancelEdit">
                                {{ $t("categories.page.cancel") }}
                            </Btn>
                        </div>
                    </form>

                    <div
                        v-else
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex items-center gap-2 font-medium text-fg"
                            >
                                <span
                                    class="size-3 shrink-0 rounded-full"
                                    :class="swatch[category.color]"
                                    aria-hidden="true"
                                />
                                {{ category.name }}
                            </p>
                            <p class="mt-0.5 text-sm text-fg-muted">
                                {{ usage(category) }}
                            </p>
                            <p
                                v-if="retireError[category.id]"
                                class="mt-1 text-sm text-danger-fg"
                                role="alert"
                            >
                                {{ retireError[category.id] }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Btn
                                :id="`category-${category.id}-edit`"
                                small
                                :aria-label="
                                    $t('categories.page.edit_name', {
                                        name: category.name,
                                    })
                                "
                                @click="startEdit(category)"
                            >
                                <Pencil class="size-4" aria-hidden="true" />
                                {{ $t("categories.page.edit") }}
                            </Btn>
                            <Btn
                                small
                                :variant="
                                    confirming === category.id
                                        ? 'danger'
                                        : 'quiet'
                                "
                                :loading="busy === category.id"
                                :data-test="`retire-${category.slug}`"
                                @click="retire(category)"
                                @blur="
                                    confirming === category.id &&
                                    busy !== category.id
                                        ? (confirming = null)
                                        : null
                                "
                            >
                                <Archive class="size-4" aria-hidden="true" />
                                {{
                                    confirming === category.id
                                        ? $t("categories.page.confirm_retire", {
                                              name: category.name,
                                          })
                                        : $t("categories.page.retire")
                                }}
                            </Btn>
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Retired categories -->
        <section
            v-if="retired.length"
            aria-labelledby="categories-retired"
            class="space-y-3"
        >
            <div>
                <h2
                    id="categories-retired"
                    class="text-base font-semibold text-fg"
                >
                    {{ $t("categories.page.retired") }}
                </h2>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("categories.page.retired_help") }}
                </p>
            </div>
            <ul
                class="divide-y divide-line rounded-card border border-line bg-surface shadow-card"
                data-test="retired"
            >
                <li
                    v-for="category in retired"
                    :key="category.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-medium text-fg">
                            <span
                                class="size-3 shrink-0 rounded-full"
                                :class="swatch[category.color]"
                                aria-hidden="true"
                            />
                            {{ category.name }}
                            <StatusChip>{{
                                $t("categories.page.retired_chip")
                            }}</StatusChip>
                        </p>
                        <p class="mt-0.5 text-sm text-fg-muted">
                            {{ usage(category) }}
                        </p>
                    </div>
                    <Btn
                        small
                        :loading="busy === category.id"
                        :aria-label="
                            $t('categories.page.restore_name', {
                                name: category.name,
                            })
                        "
                        :data-test="`restore-${category.slug}`"
                        @click="restore(category)"
                    >
                        <RotateCcw class="size-4" aria-hidden="true" />
                        {{ $t("categories.page.restore") }}
                    </Btn>
                </li>
            </ul>
        </section>

        <EmptyState
            v-if="!categories.length"
            :icon="Tags"
            :title="$t('categories.page.none_yet')"
        >
            {{ $t("categories.page.add_the_first") }}
        </EmptyState>
    </div>
</template>
