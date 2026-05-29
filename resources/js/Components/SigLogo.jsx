export default function SigLogo({ wordmark = true, size = 28 }) {
    const image = wordmark ? '/brand/deming-logo.png' : '/brand/deming-mark.png';
    const height = wordmark ? Math.round(size * 1.35) : size;

    return (
        <span className={`sig-logo ${wordmark ? '' : 'sig-logo-icon-only'}`}>
            <img
                className="sig-logo-image"
                src={image}
                alt="Deming"
                style={{ height }}
            />
        </span>
    );
}
