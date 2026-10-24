import dotenv from 'dotenv';
import { loadConfig } from './security/SecretConfig';

dotenv.config();

export const config = loadConfig();
