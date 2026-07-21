export interface PreviewCapture {
	url: string;
	viewport: { width: number; height: number };
	dom_snapshot: string;
	image_data?: string;
}

function text(value: string | null | undefined): string {
	return value?.replace(/\s+/g, ' ').trim() ?? '';
}

/** Build a bounded semantic snapshot that remains useful when image capture is unavailable. */
function domSnapshot(document: Document): string {
	const title = text(document.title);
	const headings = Array.from(document.querySelectorAll('h1, h2, h3'))
		.slice(0, 24)
		.map((element) => `${element.tagName.toLowerCase()}: ${text(element.textContent)}`)
		.filter(Boolean);
	const landmarks = Array.from(document.querySelectorAll('header, nav, main, aside, footer, form'))
		.slice(0, 20)
		.map((element) => element.tagName.toLowerCase());
	const images = Array.from(document.images)
		.slice(0, 16)
		.map((image) => `image alt="${text(image.alt)}" ${image.naturalWidth}×${image.naturalHeight}`);
	const controls = Array.from(document.querySelectorAll('a, button, input, select, textarea'))
		.slice(0, 40)
		.map(
			(element) =>
				`${element.tagName.toLowerCase()}: ${text(element.getAttribute('aria-label')) || text(element.textContent)}`,
		)
		.filter(Boolean);

	return [
		`title: ${title}`,
		`landmarks: ${landmarks.join(', ') || 'none'}`,
		`headings:\n${headings.join('\n') || 'none'}`,
		`images:\n${images.join('\n') || 'none'}`,
		`interactive controls:\n${controls.join('\n') || 'none'}`,
	]
		.join('\n')
		.slice(0, 50_000);
}

/**
 * Uses a same-origin SVG foreignObject capture. It is best-effort: DOM evidence is
 * always returned, while cross-origin assets or browser limits simply omit the image.
 */
async function screenshot(document: Document): Promise<string | undefined> {
	try {
		const html = new XMLSerializer().serializeToString(document.documentElement);
		const width = Math.min(Math.max(document.documentElement.scrollWidth, 320), 1600);
		const height = Math.min(Math.max(document.documentElement.scrollHeight, 240), 2200);
		const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><foreignObject width="100%" height="100%">${html}</foreignObject></svg>`;
		const image = new Image();
		const loaded = new Promise<void>((resolve, reject) => {
			image.onload = () => resolve();
			image.onerror = () => reject(new Error('Preview image capture was blocked.'));
		});
		image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
		await loaded;
		const canvas = document.createElement('canvas');
		canvas.width = width;
		canvas.height = height;
		const context = canvas.getContext('2d');

		if (!context) {
			return undefined;
		}

		context.drawImage(image, 0, 0);
		return canvas.toDataURL('image/png');
	} catch {
		return undefined;
	}
}

export async function capturePreview(iframe: HTMLIFrameElement): Promise<PreviewCapture | null> {
	try {
		const document = iframe.contentDocument;

		if (!document?.documentElement) {
			return null;
		}

		const imageData = await screenshot(document);

		return {
			url: iframe.src,
			viewport: { width: iframe.clientWidth, height: iframe.clientHeight },
			dom_snapshot: domSnapshot(document),
			// Keep this below the REST payload limit so a large visual never discards
			// the useful DOM evidence captured alongside it.
			image_data: imageData && imageData.length <= 3_800_000 ? imageData : undefined,
		};
	} catch {
		return null;
	}
}
