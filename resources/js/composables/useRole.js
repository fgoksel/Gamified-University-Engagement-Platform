import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";

// Temporary: until login exists, developers can preview the layout as a
// student or a teacher. Once the backend shares auth.user.role, that wins.
const DEV_ROLE_KEY = "dev-role";

function readDevRole() {
    try {
        return localStorage.getItem(DEV_ROLE_KEY) === "teacher"
            ? "teacher"
            : "student";
    } catch {
        return "student";
    }
}

const devRole = ref(readDevRole());

export function useRole() {
    const page = usePage();

    const role = computed(() => page.props.auth?.user?.role ?? devRole.value);
    const isTeacher = computed(() => role.value === "teacher");

    function setDevRole(value) {
        devRole.value = value;
        try {
            localStorage.setItem(DEV_ROLE_KEY, value);
        } catch {
            // Ignore: the preview still switches for this visit.
        }
    }

    return { role, isTeacher, setDevRole };
}