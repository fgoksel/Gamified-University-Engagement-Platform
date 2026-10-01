import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

// The logged-in user's role, shared by the backend as auth.user.role
// ("teacher" or "student" inside AppLayout).
export function useRole() {
    const page = usePage();

    const role = computed(() => page.props.auth?.user?.role ?? null);
    const isTeacher = computed(() => role.value === "teacher");
    const isStudent = computed(() => role.value === "student");

    return { role, isTeacher, isStudent };
}
