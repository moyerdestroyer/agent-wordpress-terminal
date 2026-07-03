import { render } from '@wordpress/element';
import { Terminal } from './components/Terminal';
import './admin.css';

const root = document.getElementById('awpt-root');

if (root) {
	render(<Terminal />, root);
}
