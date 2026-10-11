import { cn } from '@/lib/utils';

type BrandLogoVariant = 'auto' | 'onDark';

interface BrandLogoProps {
    /** Sizing/spacing classes applied to the wrapper (e.g. `h-9`). */
    className?: string;
    /** Accessible label for the wordmark. */
    alt?: string;
    /**
     * `auto` swaps the navy artwork in light mode for the white artwork in dark
     * mode. `onDark` always renders the white artwork, for coloured or dark
     * surfaces (such as the auth brand panel) that stay dark in both themes.
     */
    variant?: BrandLogoVariant;
}

/**
 * Product wordmark. The navy and white PNGs live in `public/` and are swapped
 * with the class-based `dark` variant so the mark always contrasts with the
 * surface behind it. The wrapper owns the height; the images scale to fit.
 */
export function BrandLogo({ className, alt = 'Rosterly', variant = 'auto' }: BrandLogoProps): JSX.Element {
    return (
        <span className={cn('inline-flex items-center', className)}>
            {variant === 'auto' ? (
                <>
                    <img
                        src="/staffLogo-naivy.png"
                        alt={alt}
                        className="h-full w-auto max-w-full object-contain dark:hidden"
                    />
                    <img
                        src="/staffLogo-white.png"
                        alt={alt}
                        className="hidden h-full w-auto max-w-full object-contain dark:block"
                    />
                </>
            ) : (
                <img
                    src="/staffLogo-white.png"
                    alt={alt}
                    className="h-full w-auto max-w-full object-contain"
                />
            )}
        </span>
    );
}
