export default function SigLogo({ wordmark = true, size = 28 }) {
    const image = wordmark ? '/brand/sigworks-logo.png' : '/brand/sigworks-mark.svg';
    const height = wordmark ? Math.round(size * 1.5) : size;
    const width = wordmark ? Math.round(height * 2030 / 384) : size;

    return (
        <span
            className={`sig-logo ${wordmark ? 'sig-logo-with-wordmark' : 'sig-logo-icon-only'}`}
            style={{
                '--sig-logo-height': `${height}px`,
                '--sig-logo-width': `${width}px`,
                '--sig-logo-image-width': `${Math.round(width * 2078 / 2030)}px`,
                '--sig-logo-image-left': `${-Math.round(width * 30 / 2030)}px`,
                '--sig-logo-image-top': `${-Math.round(height * 195 / 384)}px`,
            }}
        >
            <img
                className="sig-logo-image"
                src={image}
                alt="SigWorks"
            />
        </span>
    );
}
