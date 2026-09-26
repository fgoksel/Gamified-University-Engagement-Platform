<script setup>
import { ref } from "vue";
import { Head, useForm, router } from "@inertiajs/vue3";

const props = defineProps({
    organizers: {
        type: Array,
        default: () => [],
    },
    faculties: {
        type: Array,
        default: () => [],
    },
    flash: {
        type: Object,
        default: () => ({}),
    },
});

// Modal state
const isModalOpen = ref(false);

// Form handling via Inertia useForm
const form = useForm({
    name: "",
    email: "",
    faculty_id: "",
});

const openModal = () => {
    form.reset();
    form.clearErrors();
    isModalOpen.value = true;
};

const closeModal = () => {
    form.reset();
    form.clearErrors();
    isModalOpen.value = false;
};

const submitInvitation = () => {
    form.post("/admin/organizers", {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
};

const formatDate = (dateString) => {
    if (!dateString) return "—";
    return new Date(dateString).toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};
</script>

<template>
    <Head title="Organizer Management - Admin" />

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <!-- Top Navigation Bar -->
        <header
            class="bg-white border-b border-slate-200 sticky top-0 z-10 shadow-sm"
        >
            <div
                class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="inline-flex items-center justify-center h-9 w-9 rounded-lg bg-indigo-600 text-white font-bold text-lg"
                    >
                        G
                    </span>
                    <div>
                        <h1
                            class="font-semibold text-slate-800 text-base leading-tight"
                        >
                            Gamified University Platform
                        </h1>
                        <p class="text-xs text-slate-500 font-medium">
                            Administration / User Management
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800"
                    >
                        Admin Portal
                    </span>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Flash Notification Messages -->
            <div
                v-if="props.flash?.success"
                class="mb-6 rounded-lg bg-emerald-50 border border-emerald-200 p-4 text-emerald-800 text-sm flex items-start gap-3 shadow-sm"
            >
                <svg
                    class="h-5 w-5 text-emerald-600 shrink-0 mt-0.5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                </svg>
                <div class="flex-1 font-medium">{{ props.flash.success }}</div>
            </div>

            <div
                v-if="props.flash?.warning"
                class="mb-6 rounded-lg bg-amber-50 border border-amber-200 p-4 text-amber-800 text-sm flex items-start gap-3 shadow-sm"
            >
                <svg
                    class="h-5 w-5 text-amber-600 shrink-0 mt-0.5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                    />
                </svg>
                <div class="flex-1 font-medium">{{ props.flash.warning }}</div>
            </div>

            <!-- Page Header with Action Button -->
            <div
                class="sm:flex sm:items-center sm:justify-between mb-8 pb-5 border-b border-slate-200"
            >
                <div>
                    <h2
                        class="text-2xl font-bold text-slate-900 tracking-tight"
                    >
                        Organizer Management
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Onboard new teachers and event organizers, monitor
                        activation states, and manage permissions.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0 flex gap-3">
                    <button
                        type="button"
                        @click="openModal"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 4.5v15m7.5-7.5h-15"
                            />
                        </svg>
                        Add new Organizer
                    </button>
                </div>
            </div>

            <!-- Organizers Table -->
            <div
                class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden"
            >
                <div
                    class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50"
                >
                    <h3 class="text-sm font-semibold text-slate-700">
                        Registered & Invited Organizers ({{
                            organizers.length
                        }})
                    </h3>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-slate-200 text-left text-sm"
                    >
                        <thead class="bg-slate-50 text-slate-600 font-medium">
                            <tr>
                                <th scope="col" class="py-3.5 pl-6 pr-3">
                                    Name
                                </th>
                                <th scope="col" class="px-3 py-3.5">Email</th>
                                <th scope="col" class="px-3 py-3.5">
                                    Faculty / Unit
                                </th>
                                <th scope="col" class="px-3 py-3.5">Status</th>
                                <th scope="col" class="px-3 py-3.5">
                                    Activation Expires
                                </th>
                                <th scope="col" class="px-3 py-3.5">
                                    Created At
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-if="organizers.length === 0">
                                <td
                                    colspan="6"
                                    class="py-12 text-center text-slate-400"
                                >
                                    <svg
                                        class="mx-auto h-10 w-10 text-slate-300 mb-3"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.5"
                                            d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"
                                        />
                                    </svg>
                                    <p class="font-medium text-slate-600">
                                        No organizers registered yet
                                    </p>
                                    <p class="text-xs text-slate-400 mt-1">
                                        Click "Add new Organizer" above to send
                                        the first invitation.
                                    </p>
                                </td>
                            </tr>
                            <tr
                                v-for="user in organizers"
                                :key="user.id"
                                class="hover:bg-slate-50/70 transition"
                            >
                                <td
                                    class="py-4 pl-6 pr-3 font-medium text-slate-900 flex items-center gap-3"
                                >
                                    <div
                                        class="h-8 w-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-semibold text-xs border border-slate-200"
                                    >
                                        {{ user.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <span>{{ user.name }}</span>
                                </td>
                                <td
                                    class="px-3 py-4 text-slate-600 font-mono text-xs"
                                >
                                    {{ user.email }}
                                </td>
                                <td class="px-3 py-4 text-slate-600">
                                    <span
                                        v-if="user.faculty"
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700"
                                    >
                                        {{ user.faculty.code }} -
                                        {{ user.faculty.name }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-slate-400 text-xs italic"
                                        >Not assigned</span
                                    >
                                </td>
                                <td class="px-3 py-4">
                                    <span
                                        v-if="user.status === 'invited'"
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200/60"
                                    >
                                        Invited
                                    </span>
                                    <span
                                        v-else-if="user.status === 'active'"
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                                    >
                                        Active
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200"
                                    >
                                        Inactive
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-4 text-xs text-slate-500 font-mono"
                                >
                                    {{
                                        formatDate(
                                            user.activation_token_expires_at,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-4 text-xs text-slate-500">
                                    {{ formatDate(user.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <!-- UC-3.1.1: Individual Addition Modal -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div
                class="relative bg-white rounded-2xl shadow-xl border border-slate-200 max-w-lg w-full p-6 sm:p-8 animate-in fade-in zoom-in-95 duration-150"
            >
                <!-- Modal Header -->
                <div
                    class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6"
                >
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Add new Organizer
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Send an account activation invitation email
                            (UC-3.1.1)
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="closeModal"
                        class="text-slate-400 hover:text-slate-600 rounded-lg p-1.5 hover:bg-slate-100 transition"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <!-- Form -->
                <form @submit.prevent="submitInvitation" class="space-y-4">
                    <!-- Full Name -->
                    <div>
                        <label
                            for="name"
                            class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1"
                        >
                            Full Name <span class="text-rose-500">*</span>
                        </label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Dr. Kovács Péter"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600 transition"
                            :class="{
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500':
                                    form.errors.name,
                            }"
                        />
                        <p
                            v-if="form.errors.name"
                            class="mt-1 text-xs text-rose-600 font-medium"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <!-- Email Address -->
                    <div>
                        <label
                            for="email"
                            class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1"
                        >
                            Email Address <span class="text-rose-500">*</span>
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="e.g. kovacs.peter@mik.pte.hu"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600 transition"
                            :class="{
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500':
                                    form.errors.email,
                            }"
                        />
                        <p
                            v-if="form.errors.email"
                            class="mt-1 text-xs text-rose-600 font-medium"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Faculty / Organizational Unit -->
                    <div>
                        <label
                            for="faculty_id"
                            class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1"
                        >
                            Organizational Unit / Faculty
                            <span class="text-slate-400 font-normal lowercase"
                                >(optional)</span
                            >
                        </label>
                        <select
                            id="faculty_id"
                            v-model="form.faculty_id"
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600 transition bg-white"
                            :class="{
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500':
                                    form.errors.faculty_id,
                            }"
                        >
                            <option value="">
                                -- Select Faculty / Unit (Optional) --
                            </option>
                            <option
                                v-for="faculty in faculties"
                                :key="faculty.id"
                                :value="faculty.id"
                            >
                                {{ faculty.code }} - {{ faculty.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.faculty_id"
                            class="mt-1 text-xs text-rose-600 font-medium"
                        >
                            {{ form.errors.faculty_id }}
                        </p>
                    </div>

                    <!-- Modal Actions -->
                    <div
                        class="pt-6 border-t border-slate-100 flex items-center justify-end gap-3 mt-6"
                    >
                        <button
                            type="button"
                            @click="closeModal"
                            :disabled="form.processing"
                            class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition focus:outline-none"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            <svg
                                v-if="form.processing"
                                class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                ></path>
                            </svg>
                            <span>{{
                                form.processing
                                    ? "Sending..."
                                    : "Send invitation"
                            }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
