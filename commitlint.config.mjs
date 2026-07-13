export default {
    extends: ['@commitlint/config-conventional'],
    rules: {
        'type-enum': [
            2,
            'always',
            [
                'build',
                'chore',
                'ci',
                'deps',
                'docs',
                'feat',
                'fix',
                'perf',
                'refactor',
                'revert',
                'style',
                'test',
            ],
        ],
    },
    // Dependabot generates verbose commit bodies whose lines exceed body-max-line-length.
    // Skip its bot commits (identified by their sign-off trailer); the PR title we squash-merge
    // is still validated by our own conventions.
    ignores: [(message) => /^Signed-off-by: dependabot\[bot\]/m.test(message)],
};
