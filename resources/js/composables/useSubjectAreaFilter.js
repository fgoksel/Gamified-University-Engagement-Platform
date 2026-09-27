import { ref } from "vue";

// Shared by every copy of the Subject Area panel (desktop sidebar and mobile
// menu) and, later, by the event pages. Declared outside the function so the
// values survive page changes (UC-1.2: filters persist across pages).
const scope = ref("mine");
const search = ref("");

export function useSubjectAreaFilter() {
    return { scope, search };
}