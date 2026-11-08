import pluginVue from 'eslint-plugin-vue'
import prettierConfig from '@vue/eslint-config-prettier'

// Task #95: interface text comes from the dictionary (lang/en.json), never typed into a screen.
// Two rules check every file of the front end:
//   - no text between tags and no text in the attributes that people read (title, label, ...)
//   - no sentence typed into a script or an expression

// A sentence: a capitalised word followed by more ("Light mode", "Part 1 of 2", "Something went
// wrong. Try again."). Class lists and keys such as "Escape" do not match.
const sentence = '^[A-Z][a-z]+[\\s,:;.!?]'

// Properties that hold text people read ({ label: "Cancel" }), even when it is one word.
const textProperty =
    '^(label|title|text|message|description|placeholder|hint|caption|heading|subtitle|tooltip)$'

// A word of ordinary text next to a space or a full stop, as in ` students` or ` would receive `.
// A class such as "text-sm" or an address such as "/events/" has no such word.
const wordInText = '(^|\\s)[a-z]{3,}(\\.?(\\s|$)|[,!?:;])'

const message =
    'Do not type interface text here. Add it to lang/en.json and use t("key") or $t("key").'

const noTypedText = [
    {
        selector: `Literal[value=/${sentence}/]`,
        message,
    },
    {
        selector: `TemplateElement[value.cooked=/${sentence}/]`,
        message,
    },
    {
        selector: `Property[key.name=/${textProperty}/] > Literal[value=/[A-Za-z]/]`,
        message,
    },
    // Text built from values: `${n} students`, "would receive", "1 point".
    {
        selector: `TemplateElement[value.cooked=/${wordInText}/]`,
        message,
    },
    // A short word between values: `${step} of ${total}`.
    {
        selector: 'TemplateElement[value.cooked=/(^|\\s)(of|to|in|on|at|by|or|an|is|as)(\\s|$)/]',
        message,
    },
    {
        selector: 'Literal[value=/^[a-z]{2,}([\\s,]+[a-z]{2,})+[.!?]?$/]',
        message,
    },
    {
        selector: 'Literal[value=/^[0-9]+ [a-z]/]',
        message,
    },
    // One capitalised word handed on as a text ({ accepted: "Accepted" }, cond ? "Open" : "Closed", "Re-apply").
    // Not a comparison (event.key === "Escape"), an import or the name of a component.
    {
        selector: `:not(:matches(BinaryExpression, SwitchCase, ImportDeclaration, ExportNamedDeclaration, ExportAllDeclaration, MemberExpression, ImportExpression, Property[key.name="name"])) > Literal[value=/^[A-Z][a-z]+[A-Za-z' -]*[.…!?:]*$/]:not(.key)`,
        message,
    },
]

// Rules for the template only: what an expression between {{ }} prints.
const rawValue = '^(category|status|type|kind|state|decision)$'
const printed = 'VElement > VExpressionContainer'
const noTypedTextInTemplate = [
    // {{ event.status }} prints the English word the server stores. Map it to a text of the dictionary.
    {
        selector: `:matches(${printed}, ${printed} :not(:matches(MemberExpression[computed=true], CallExpression, BinaryExpression, Property, TemplateLiteral, UnaryExpression))) > MemberExpression[object.property.name!="errors"][property.name=/${rawValue}/]`,
        message:
            'Do not print a raw value (a status, a category, a type) as text. Map it to a text of the dictionary.',
    },
    // A word typed into an expression that prints: {{ n === 1 ? "event" : "events" }}
    {
        selector: `:matches(${printed}, ${printed} :not(:matches(CallExpression, MemberExpression, BinaryExpression, Property, ImportExpression))) > Literal[value=/[A-Za-z]/]`,
        message,
    },
]

export default [
    ...pluginVue.configs['flat/essential'],
    prettierConfig,
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        files: ['resources/js/**/*.{vue,js}'],
        rules: {
            'vue/no-bare-strings-in-template': [
                'error',
                {
                    // Punctuation and symbols between two {{ values }} are not text.
                    allowlist: [
                        '(', ')', ',', '.', '&', '+', '-', '=', '*', '/', '#', '%', '!', '?', ':', '[', ']', '{', '}',
                        '<', '>', '|', '·', '•', '‐', '–', '—', '−', '×', '©', '→', '←', '↑', '↓',
                    ],
                    attributes: {
                        '/.+/': [
                            'title',
                            'aria-label',
                            'aria-placeholder',
                            'aria-roledescription',
                            'aria-valuetext',
                            'placeholder',
                            'label',
                            'hint',
                            'caption',
                            'alt',
                            'description',
                            'heading',
                            'tooltip',
                        ],
                    },
                    directives: ['v-text'],
                },
            ],
            'no-restricted-syntax': ['error', ...noTypedText],
            'vue/no-restricted-syntax': ['error', ...noTypedText, ...noTypedTextInTemplate],
        },
    },
]
