/**
 * The visibilities the picker offers, in the order it offers them. The
 * workspace's policy answers per visibility and in its own order; this is what
 * the form reads.
 */
const PICKER_ORDER: readonly App.Enums.ChannelVisibility[] = [
    'public',
    'private',
];

/**
 * The visibilities the workspace's channel-creation policy leaves open to the
 * viewer, in the order the picker offers them.
 *
 * The create endpoint re-enforces the policy, so this only keeps the form from
 * offering a choice that would come straight back as a 403.
 */
export function creatableVisibilities(
    creatable: readonly App.Enums.ChannelVisibility[] | undefined,
): App.Enums.ChannelVisibility[] {
    return PICKER_ORDER.filter((visibility) =>
        (creatable ?? []).includes(visibility),
    );
}

/**
 * Whether there is any channel at all for the viewer to create here.
 *
 * The gate on every affordance that opens the create dialog — the sidebar's
 * "+", the "New" menu, the first-run welcome, and the palette's *Create a
 * channel* — now that the dialog is a shell singleton rather than a wrapper
 * around each of those triggers. Sharing one reading is what keeps an
 * affordance from offering a dialog the policy would refuse to mount.
 */
export function canCreateChannel(
    creatable: readonly App.Enums.ChannelVisibility[] | undefined,
): boolean {
    return creatableVisibilities(creatable).length > 0;
}
