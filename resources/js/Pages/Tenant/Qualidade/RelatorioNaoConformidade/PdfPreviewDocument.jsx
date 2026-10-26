import PdfPreviewPage from './PdfPreviewPage';

export default function PdfPreviewDocument({ pdf, name, scrollRoot }) {
    return <div role="document" aria-label={name} className="grid gap-3">
        {Array.from({ length: pdf.numPages }, (_, index) => <PdfPreviewPage key={index + 1} pdf={pdf} pageNumber={index + 1} name={name} lazy scrollRoot={scrollRoot} />)}
    </div>;
}
