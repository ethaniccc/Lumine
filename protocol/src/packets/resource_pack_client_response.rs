use crate::CanIo;
use std::convert::TryFrom;
use std::io::ErrorKind;

#[derive(Debug, Clone)]
pub struct ResourcePackClientResponse {
    pub response: Response,
    pub packs_to_download: Vec<String>,
}

impl CanIo for ResourcePackClientResponse {
    fn write(&self, vec: &mut Vec<u8>) {
        self.response.write(vec);
        (self.packs_to_download.len() as u16).write(vec);
        for pack in &self.packs_to_download {
            pack.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let response = Response::read(src, offset)?;
        let len = u16::read(src, offset)?;
        let mut packs_to_download = Vec::with_capacity(len as usize);
        for _ in 0..len {
            packs_to_download.push(String::read(src, offset)?)
        }
        Ok(Self {
            response,
            packs_to_download,
        })
    }
}

#[derive(Debug, Clone)]
pub enum Response {
    PackResponseRefused = 0x01,
    PackResponseSendPacks = 0x02,
    PackResponseAllPacksDownloaded = 0x03,
    PackResponseCompleted = 0x04,
}

impl TryFrom<u8> for Response {
    type Error = std::io::Error;

    fn try_from(value: u8) -> Result<Self, Self::Error> {
        Ok(match value {
            0x01 => Self::PackResponseRefused,
            0x02 => Self::PackResponseSendPacks,
            0x03 => Self::PackResponseAllPacksDownloaded,
            0x04 => Self::PackResponseCompleted,
            _ => {
                return Err(std::io::Error::new(
                    ErrorKind::InvalidData,
                    "Invalid PackResponse From Client!",
                ))
            }
        })
    }
}

impl CanIo for Response {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as u8).write(vec);
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        Ok(Self::try_from(u8::read(src, offset)?)?)
    }
}
