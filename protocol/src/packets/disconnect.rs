use crate::{CanIo};

#[derive(Debug, Clone)]
pub struct Disconnect {
    pub hide_disconnection_screen: bool,
    pub message: Option<String>,
}

impl CanIo for Disconnect {
    fn write(&self, vec: &mut Vec<u8>) {
        self.hide_disconnection_screen.write(vec);
        if !self.hide_disconnection_screen {
            if let Some(msg) = &self.message {
                msg.write(vec);
            }
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let hide_disconnection_screen = bool::read(src, offset).unwrap();
        let mut message: Option<String> = None;
        if !hide_disconnection_screen {
            message = Option::from(String::read(src, offset)?);
        };
        Ok(Self {
            hide_disconnection_screen,
            message
        })
    }
}