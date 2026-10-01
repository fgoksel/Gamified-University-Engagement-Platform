import { ref } from "vue";

// Shared by every copy of the Subject Area panel (desktop sidebar and mobile
// menu) and by the pages it filters (Leaderboard, Events). Declared outside
// the function so the values survive page changes (UC-1.2: filters persist
// across pages).
const scope = ref("mine"); // "mine" or "all"
const search = ref("");
const selectedArea = ref(null); // { id, title, code } or null
const expandedGroups = ref(new Set()); // group keys, see groupKey()
const initialized = ref(false);

// Faculty id, or "university" for areas without a faculty
export function groupKey(group) {
    return group.id ?? "university";
}

// Lower case, no accents: "Pécs" matches "pecs"
export function normalize(text) {
    return (text ?? "")
        .normalize("NFD")
        .replace(/\p{Diacritic}/gu, "")
        .toLowerCase()
        .trim();
}

export function useSubjectAreaFilter() {
    function select(area) {
        selectedArea.value =
            selectedArea.value?.id === area.id
                ? null
                : { id: area.id, title: area.title, code: area.code };
    }

    function clearSelection() {
        selectedArea.value = null;
    }

    function isExpanded(key) {
        return expandedGroups.value.has(key);
    }

    function toggleGroup(key) {
        if (expandedGroups.value.has(key)) {
            expandedGroups.value.delete(key);
        } else {
            expandedGroups.value.add(key);
        }
    }

    // Open the teacher's own faculty the first time the panel is shown
    function initExpanded(keys) {
        if (initialized.value) return;
        keys.forEach((key) => expandedGroups.value.add(key));
        initialized.value = true;
    }

    return {
        scope,
        search,
        selectedArea,
        select,
        clearSelection,
        isExpanded,
        toggleGroup,
        initExpanded,
    };
}
