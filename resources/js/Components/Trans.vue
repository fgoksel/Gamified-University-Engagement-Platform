<script>
import { t } from "../i18n.js";

// A text with other markup inside it, like "Filtering by <strong>Maths</strong>" (Task #95).
// The sentence stays one entry of the dictionary, "Filtering by :title", so a translator
// can put the parts in the order their language needs. The markup for a :placeholder is
// given as a slot of the same name:
//
//   <Trans k="subject_areas.filtering_by">
//       <template #title><strong>{{ area.title }}</strong></template>
//   </Trans>
//
// A :placeholder without a slot is filled from `params`, as in t().
export default {
    name: "Trans",
    props: {
        k: { type: String, required: true },
        params: { type: Object, default: () => ({}) },
    },
    setup(props, { slots }) {
        return () => {
            const names = Object.keys(slots).filter(
                (name) => name !== "default",
            );
            const text = t(props.k, props.params);

            if (names.length === 0) return text;

            // Split in front of each :name that has a slot; the names are kept by the group.
            const pattern = new RegExp(`:(${names.join("|")})(?![A-Za-z0-9_])`);

            return text
                .split(pattern)
                .map((part, index) => (index % 2 === 1 ? slots[part]() : part));
        };
    },
};
</script>
