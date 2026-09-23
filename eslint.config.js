import pluginVue from 'eslint-plugin-vue'
import prettierConfig from '@vue/eslint-config-prettier'

export default [
    ...pluginVue.configs['flat/essential'],
    prettierConfig,
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
]