// Jest 27's jsdom lacks these platform APIs required by React Router 7.
import { TextDecoder, TextEncoder } from 'util';
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;
