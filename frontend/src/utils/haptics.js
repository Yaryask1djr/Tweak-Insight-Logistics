/**
 * Mobile Browser Haptic Feedback Utilities.
 *
 * Provides physical vibration confirmation for outdoor Kano dispatch drivers
 * mounted on motorcycles/tricycles, especially for delivery OTP verification
 * and critical job claim actions.
 */

export const triggerHaptic = (pattern = [100, 50, 100]) => {
    if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
        try {
            navigator.vibrate(pattern);
        } catch (e) {
            // Silently ignore if vibrate is blocked or unsupported
        }
    }
};

export const triggerOtpSuccessHaptic = () => {
    // Double pulse confirmation pattern specifically for verified delivery receipt
    triggerHaptic([100, 50, 100]);
};

export const triggerActionTapHaptic = () => {
    // Subtle physical feedback on critical driver button taps
    triggerHaptic(40);
};
